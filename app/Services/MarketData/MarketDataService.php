<?php

namespace App\Services\MarketData;

use App\Models\Candle;
use App\Models\CandleSyncState;
use App\Models\MarketSnapshot;
use App\Models\Setting;
use App\Models\TradingSymbol;
use App\Services\MT5\CandleProvider;
use App\Services\Trading\TechnicalAnalysisService;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Candle synchronization with per-symbol/timeframe cursor, gap detection,
 * batch upsert, and health monitoring. Prefers completed candles.
 */
class MarketDataService
{
    public function __construct(
        private CandleProvider $provider,
        private TechnicalAnalysisService $ta,
        private \App\Services\System\AppLogger $log,
    ) {}

    /**
     * Automatic timeframe selection. Each symbol carries an analysis_timeframe
     * (updated from the market regime by the discovery agent). Execution and
     * SL/TP monitoring ALWAYS stay on M1 — this only changes the analysis lens.
     */
    public function analysisTimeframe(string $symbol): string
    {
        $tf = strtoupper((string) (TradingSymbol::where('symbol', $symbol)->value('analysis_timeframe') ?? 'H1'));
        if (! isset(CandleProvider::TIMEFRAME_MINUTES[$tf])) return 'H1';
        return $tf;
    }

    /** Regime → timeframe map (deterministic, admin-configurable). */
    public static function regimeTimeframe(string $regime): string
    {
        $map = json_decode((string) Setting::get('timeframe.regime_map', '{"TRENDING":"H4","RANGING":"M15","VOLATILE":"H1","UNKNOWN":"H1"}'), true) ?: [];
        $tf = strtoupper((string) ($map[strtoupper($regime)] ?? 'H1'));
        return isset(CandleProvider::TIMEFRAME_MINUTES[$tf]) ? $tf : 'H1';
    }

    /**
     * Sync latest candles. IMPORTANT bridge semantics (verified empirically):
     * the bridge interprets startTime as the END of the window in MT5 server
     * time (which runs ahead of UTC yet is labeled with Z), so offset-based
     * fetching returns duplicates and never advances. We therefore anchor on
     * COUNT: fetch the latest N bars (N sized from the gap since last sync)
     * and rely on batch upserts for idempotency.
     */
    public function sync(string $symbol, string $timeframe, int $limit = 500): array
    {
        $state = CandleSyncState::firstOrCreate(
            ['symbol' => $symbol, 'timeframe' => strtoupper($timeframe)]
        );

        try {
            $minutes = CandleProvider::TIMEFRAME_MINUTES[strtoupper($timeframe)] ?? 1;
            $last = Candle::where('symbol', $symbol)
                ->where('timeframe', strtoupper($timeframe))
                ->max('candle_time');

            if ($last) {
                // +2 interval buffer for the in-flight forming bar and clock skew.
                $spanMin = max(1, (time() - strtotime($last)) / 60);
                $limit = min(5000, (int) ceil($spanMin / $minutes) + 2);
                $payload = $this->provider->klines($symbol, $timeframe, $limit);
            } else {
                $payload = $this->provider->klines($symbol, $timeframe, $limit);
            }

            $candles = $payload['candles'] ?? [];
            // Drop the final bar: in a latest-anchored fetch it is the still-forming
            // candle. (Wall-clock check also holds when the MT5 server clock runs
            // ahead of UTC, since the diff then goes negative.)
            $candles = $this->dropFormingCandle($candles, $timeframe);

            $inserted = 0; $invalid = 0;
            $rows = [];
            foreach ($candles as $c) {
                if (! CandleProvider::isValidOhlc($c)) { $invalid++; continue; }
                $rows[] = CandleProvider::normalizeCandle($symbol, $timeframe, $c);
            }

            if ($rows !== []) {
                // Batch upsert in chunks to avoid duplicates (unique symbol+timeframe+candle_time).
                foreach (array_chunk($rows, 500) as $chunk) {
                    $before = Candle::where('symbol', $symbol)->where('timeframe', strtoupper($timeframe))->count();
                    Candle::upsert($chunk, ['symbol', 'timeframe', 'candle_time'], ['open', 'high', 'low', 'close', 'tick_volume', 'spread', 'real_volume', 'source', 'updated_at']);
                    $after = Candle::where('symbol', $symbol)->where('timeframe', strtoupper($timeframe))->count();
                    $inserted += max(0, $after - $before);
                }
            }

            $maxTime = Candle::where('symbol', $symbol)->where('timeframe', strtoupper($timeframe))->max('candle_time');
            $state->update([
                'last_candle_time' => $maxTime, 'status' => 'OK',
                'last_error' => null, 'last_synced_at' => now(),
            ]);

            $gaps = $this->detectGaps($symbol, $timeframe);
            $this->log->info('market', "Synced {$symbol} {$timeframe}", ['fetched' => count($candles), 'inserted' => $inserted, 'invalid' => $invalid, 'gaps' => $gaps]);

            return ['symbol' => $symbol, 'timeframe' => $timeframe, 'fetched' => count($candles), 'inserted' => $inserted, 'invalid' => $invalid, 'gaps' => $gaps];
        } catch (\Throwable $e) {
            $state->update(['status' => 'ERROR', 'last_error' => substr($e->getMessage(), 0, 1000), 'last_synced_at' => now()]);
            $this->log->error('market', "Sync failed {$symbol} {$timeframe}", ['error' => substr($e->getMessage(), 0, 300)]);
            throw $e;
        }
    }

    /** Backfill a historical range [from, to] (UTC). Used for initialization. */
    public function backfill(string $symbol, string $timeframe, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $payload = $this->provider->klines($symbol, $timeframe, 5000, $from->getTimestamp() * 1000, $to->getTimestamp() * 1000);
        $candles = $payload['candles'] ?? [];
        $rows = [];
        foreach ($candles as $c) {
            if (! CandleProvider::isValidOhlc($c)) continue;
            $rows[] = CandleProvider::normalizeCandle($symbol, $timeframe, $c);
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            Candle::upsert($chunk, ['symbol', 'timeframe', 'candle_time'], ['open', 'high', 'low', 'close', 'tick_volume', 'spread', 'real_volume', 'source', 'updated_at']);
        }
        return ['symbol' => $symbol, 'timeframe' => $timeframe, 'stored' => count($rows)];
    }

    /** Build a market snapshot with indicators for a symbol (analysis TF auto-selected). */
    public function snapshot(string $symbol, ?string $timeframe = null): MarketSnapshot
    {
        $timeframe = strtoupper($timeframe ?? $this->analysisTimeframe($symbol));
        $candles = Candle::where('symbol', $symbol)->where('timeframe', strtoupper($timeframe))
            ->orderBy('candle_time', 'desc')->limit(250)->get()->reverse()->values();
        if ($candles->isEmpty()) {
            throw new \RuntimeException("No candles for {$symbol} {$timeframe}");
        }
        $closes = $candles->pluck('close')->map(fn ($v) => (float) $v)->all();
        $highs = $candles->pluck('high')->map(fn ($v) => (float) $v)->all();
        $lows = $candles->pluck('low')->map(fn ($v) => (float) $v)->all();
        $last = $candles->last();

        $indicators = [
            'sma20' => $this->ta->sma($closes, 20),
            'sma50' => $this->ta->sma($closes, 50),
            'ema20' => $this->ta->ema($closes, 20),
            'rsi14' => $this->ta->rsi($closes, 14),
            'atr14' => $this->ta->atr($highs, $lows, $closes, 14),
            'donchian20_high' => $this->ta->highest($highs, 20),
            'donchian20_low' => $this->ta->lowest($lows, 20),
        ];
        $trend = $this->ta->trend($closes);
        $atr = $indicators['atr14'] ?? 0;
        $volPct = $last->close > 0 ? round(($atr / (float) $last->close) * 100, 4) : 0;

        return MarketSnapshot::create([
            'symbol' => $symbol,
            'price' => $last->close,
            'timeframe' => strtoupper($timeframe),
            'candle_time' => $last->candle_time,
            'atr' => $atr,
            'volatility_pct' => $volPct,
            'trend' => $trend,
            'regime' => $this->ta->regime($closes, (float) ($indicators['atr14'] ?? 0)),
            'indicators' => $indicators,
        ]);
    }

    /** Health: stale/missing candles per monitored symbol. */
    public function health(int $maxStaleSeconds = 300): array
    {
        $symbols = TradingSymbol::where('is_enabled', true)->pluck('symbol');
        $report = [];
        foreach ($symbols as $symbol) {
            $last = Candle::where('symbol', $symbol)->where('timeframe', 'M1')->max('candle_time');
            $age = $last ? (time() - strtotime($last)) : null;
            // Forex weekends: market closed Sat/Sun — mark accordingly, not stale.
            $dow = (int) gmdate('N');
            $weekend = $dow >= 6;
            $status = $last === null ? 'MISSING' : (($age > $maxStaleSeconds && ! $weekend) ? 'STALE' : 'OK');
            $report[] = ['symbol' => $symbol, 'last_candle' => $last, 'age_seconds' => $age, 'status' => $status];
        }
        return $report;
    }

    public function isFresh(int $maxStaleSeconds = 300): bool
    {
        foreach ($this->health($maxStaleSeconds) as $row) {
            $dow = (int) gmdate('N');
            if ($dow >= 6) continue;
            if (in_array($row['status'], ['MISSING', 'STALE'], true)) return false;
        }
        return true;
    }

    private function dropFormingCandle(array $candles, string $timeframe): array
    {
        if (count($candles) < 2) return $candles;
        $minutes = CandleProvider::TIMEFRAME_MINUTES[strtoupper($timeframe)] ?? 1;
        $last = end($candles);
        $lastTs = (int) ($last['time'] ?? 0);
        if ($lastTs > 0 && (time() - $lastTs) < ($minutes * 60)) {
            array_pop($candles);
        }
        return $candles;
    }

    private function detectGaps(string $symbol, string $timeframe): int
    {
        $minutes = CandleProvider::TIMEFRAME_MINUTES[strtoupper($timeframe)] ?? null;
        if (! $minutes) return 0;
        $times = Candle::where('symbol', $symbol)->where('timeframe', strtoupper($timeframe))
            ->orderBy('candle_time', 'desc')->limit(200)->pluck('candle_time')
            ->map(fn ($t) => strtotime($t))->sort()->values()->all();
        $gaps = 0;
        for ($i = 1; $i < count($times); $i++) {
            if (($times[$i] - $times[$i - 1]) > ($minutes * 60 * 1.5)) $gaps++;
        }
        return $gaps;
    }
}
