<?php

namespace App\Services\MT5;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Dedicated candle-data provider for the MT5 API bridge (FastAPI, see reference).
 * READ-ONLY market data: /health, /symbols, /klines, /tick.
 * NEVER used for order execution.
 */
class CandleProvider
{
    public const INTERVAL_MAP = [
        'M1' => '1m', 'M2' => '2m', 'M3' => '3m', 'M4' => '4m',
        'M5' => '5m', 'M6' => '6m', 'M10' => '10m', 'M12' => '12m',
        'M15' => '15m', 'M20' => '20m', 'M30' => '30m',
        'H1' => '1h', 'H2' => '2h', 'H3' => '3h', 'H4' => '4h',
        'H6' => '6h', 'H8' => '8h', 'H12' => '12h',
        'D1' => '1d', 'W1' => '1w', 'MN1' => '1mo',
    ];

    public const TIMEFRAME_MINUTES = [
        'M1' => 1, 'M5' => 5, 'M15' => 15, 'M30' => 30,
        'H1' => 60, 'H4' => 240, 'D1' => 1440,
    ];

    public function __construct(
        private string $baseUrl = '',
        private string $apiKey = '',
        private int $timeout = 20,
    ) {
        $this->baseUrl = $baseUrl ?: (string) config('services.mt5.base_url', env('MT5_BRIDGE_URL', 'https://mt5.graphichat.ai'));
        $this->apiKey = $apiKey ?: (string) config('services.mt5.key', env('MT5_BRIDGE_KEY', ''));
        $this->timeout = (int) config('services.mt5.timeout', env('MT5_BRIDGE_TIMEOUT', 20));
    }

    private function client()
    {
        $req = Http::timeout($this->timeout)->acceptJson();
        if ($this->apiKey !== '') {
            $req = $req->withHeader('X-API-Key', $this->apiKey);
        }
        return $req;
    }

    /** Open health endpoint (no auth). */
    public function health(): array
    {
        try {
            $res = $this->client()->get(rtrim($this->baseUrl, '/') . '/health');
            if ($res->failed()) {
                return ['ok' => false, 'status' => $res->status(), 'body' => $res->json(), 'reason' => 'Bridge HTTP ' . $res->status()];
            }
            $body = $res->json();
            $initialized = (bool) ($body['initialized'] ?? false);
            $connected = (bool) ($body['connected'] ?? false);
            $reason = null;
            if ($initialized && ! $connected) {
                $reason = 'MT5 terminal initialized but NOT logged into its trade server'
                    . ' (' . ($body['company'] ?? '?') . ' / ' . ($body['server'] ?? '?') . ').'
                    . ' The bridge needs a working MT5 login — check the bridge container/terminal, not this app.';
            } elseif (! $initialized) {
                $reason = 'MT5 terminal not initialized in the bridge.';
            }
            return ['ok' => $initialized && $connected, 'body' => $body, 'reason' => $reason];
        } catch (\Throwable $e) {
            Log::warning('MT5 health check failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage(), 'reason' => 'Bridge unreachable: ' . $e->getMessage()];
        }
    }

    /** Discover broker symbols. */
    public function symbols(string $q = '', int $limit = 500): array
    {
        $res = $this->client()->get(rtrim($this->baseUrl, '/') . '/symbols', [
            'q' => $q, 'limit' => max(1, min(12226, $limit)),
        ]);
        if ($res->failed()) {
            throw new \RuntimeException('MT5 /symbols failed: HTTP ' . $res->status() . ' ' . $res->body());
        }
        return $res->json();
    }

    public function toBridgeInterval(string $timeframe): string
    {
        $tf = strtoupper($timeframe);
        if (! isset(self::INTERVAL_MAP[$tf])) {
            throw new \InvalidArgumentException("Unsupported timeframe: {$timeframe}");
        }
        return self::INTERVAL_MAP[$tf];
    }

    /**
     * Fetch candles. $fromMs/$toMs are millisecond epoch (Binance-style).
     * Returns raw bridge payload: ['symbol','interval','count','candles'=>[...]].
     */
    public function klines(string $symbol, string $timeframe, int $limit = 500, int $fromMs = 0, int $toMs = 0): array
    {
        $params = [
            'symbol' => $symbol,
            'interval' => $this->toBridgeInterval($timeframe),
            'limit' => max(1, min(5000, $limit)),
        ];
        if ($fromMs > 0) $params['startTime'] = $fromMs;
        if ($toMs > 0) $params['endTime'] = $toMs;

        $res = $this->client()->get(rtrim($this->baseUrl, '/') . '/klines', $params);
        if ($res->failed()) {
            throw new \RuntimeException("MT5 /klines failed for {$symbol} {$timeframe}: HTTP " . $res->status() . ' ' . substr($res->body(), 0, 500));
        }
        return $res->json();
    }

    /** Latest tick (bid/ask) for spread modelling. */
    public function tick(string $symbol): array
    {
        $res = $this->client()->get(rtrim($this->baseUrl, '/') . '/tick', ['symbol' => $symbol]);
        if ($res->failed()) {
            throw new \RuntimeException("MT5 /tick failed for {$symbol}: HTTP " . $res->status());
        }
        return $res->json();
    }

    /** Normalize a bridge candle to DB-ready row. Bridge times are UTC seconds. */
    public static function normalizeCandle(string $symbol, string $timeframe, array $c): array
    {
        return [
            'symbol' => $symbol,
            'timeframe' => strtoupper($timeframe),
            'candle_time' => gmdate('Y-m-d H:i:s', (int) $c['time']),
            'open' => $c['open'], 'high' => $c['high'],
            'low' => $c['low'], 'close' => $c['close'],
            'tick_volume' => (int) ($c['tick_volume'] ?? 0),
            'spread' => isset($c['spread']) ? (int) $c['spread'] : null,
            'real_volume' => $c['real_volume'] ?? null,
            'source' => 'mt5',
        ];
    }

    public static function isValidOhlc(array $c): bool
    {
        foreach (['open', 'high', 'low', 'close'] as $k) {
            if (! isset($c[$k]) || ! is_numeric($c[$k]) || $c[$k] <= 0) return false;
        }
        return $c['high'] >= max($c['open'], $c['close'])
            && $c['low'] <= min($c['open'], $c['close'])
            && $c['high'] >= $c['low'];
    }
}
