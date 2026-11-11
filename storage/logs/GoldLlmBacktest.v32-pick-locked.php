<?php

namespace App\Console\Commands;

use App\Models\Candle;
use App\Services\LLM\OpenRouterService;
use App\Services\Trading\TechnicalAnalysisService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Gold (XAUUSD) LLM-batch-limit backtester. The decision layer is entirely
 * LLM-driven: every evaluation sends the trailing window of CLOSED candles to
 * the model, and every order batch (entries, SL/TP multiples) comes from the
 * model's prediction. No rule-based fallback exists — HOLD, low confidence,
 * LLM errors or incomplete grids all mean no trade.
 *
 * Strategy under test (exactly as requested):
 *  1. At a closed candle, send the trailing lookback window (default 7 days)
 *     to the LLM and ask for next-move prediction.
 *  2. If meaningful (confidence >= threshold), place a small batch of LIMIT
 *     orders around the close. First fill wins -> cancel siblings.
 *  3. Hold the position until SL / TP / max-hold, all evaluated on CLOSED
 *     candles only (close-price triggers + conservative high/low range for
 *     fills and SL/TP touches; SL wins ties).
 *  4. After completion, re-evaluate. If the LLM says HOLD / low confidence,
 *     wait --cooldown candles before asking again.
 *  5. Unfilled batches expire after --expiry candles -> cancel all, cooldown,
 *     re-evaluate via LLM.
 *
 * No lookahead: the LLM context and every trigger use candles with index <= i
 * (closed). Candle i+1 is only touched when the simulation clock advances.
 */
class GoldLlmBacktest extends Command
{
    protected $signature = 'bot:backtest-gold-llm
        {--symbol=XAUUSD : Symbol to backtest}
        {--timeframe=H1 : Analysis timeframe (candles that drive eval + expiry)}
        {--from= : Range start (Y-m-d). Default: 1 month before latest candle}
        {--to= : Range end (Y-m-d). Default: latest candle}
        {--lookback-days=7 : Context window of history sent to the LLM}
        {--expiry=24 : Cancel unfilled batch after N analysis candles}
        {--cooldown=2 : Candles to wait after HOLD / expiry / flat re-eval}
        {--min-confidence=0.55 : Below this the prediction is "not meaningful"}
        {--balance=10000 : Starting balance}
        {--risk-pct=1.0 : Risk per trade as % of equity (sizes volume)}
        {--volume=0.01 : Fallback fixed volume (lots) if risk sizing fails}
        {--sl-atr=1.5 : Stop distance in ATR multiples}
        {--tp-atr=3.0 : Fixed-mode take-profit in ATR multiples (ignored in trail mode)}
        {--exit-mode=trail : Exits: trail (ATR trailing SL, no fixed TP) or fixed (fixed SL+TP)}
        {--trail-atr=1.0 : Trail distance behind peak excursion in ATR multiples}
        {--max-hold=72 : Max position hold in analysis candles}
        {--agent=backtest-gold : OpenRouter agent/model key}
        {--model= : Override the OpenRouter model (e.g. deepseek/deepseek-v3.2). Default: settings key}
        {--decision=pick : Decision mode: pick (LLM picks) | gate (rule proposes, LLM approves) | mock (rule trades alone, no LLM calls)}
        {--gate-score=60 : Gate mode: approve candidates scoring at/above this (0-100)}
        {--trend-filter=1 : Block signals against the window trend (1=on, 0=off)}
        {--breakeven-r=1.0 : Move SL to entry once profit hits NxR (0=off)}
        {--loss-brake=2 : Sit out after N consecutive losing trades (0=off)}
        {--loss-cooldown=12 : Candles to skip once the loss brake triggers}
        {--candidate-tf= : Faster TF proposing setups (e.g. M15); decisions stay on --timeframe. Default: same as --timeframe}
        {--regime-tf= : Monthly switch TF (e.g. H4); trade a month only if trailing ADX clears --regime-adx. Empty=off}
        {--regime-adx=25 : Monthly switch threshold (ADX over --regime-lookback-days)}
        {--regime-lookback-days=30 : Trailing days feeding the monthly regime read}
        {--spread-pips= : Override spread (default: trading_symbols row or 0)}
        {--pip-size= : Override pip size (default: trading_symbols row or 0.1)}
        {--report= : Write JSON report to this path (default storage/logs/)}
        {--txt-report= : Write human-readable trade-wise TXT report (default alongside JSON)}';

    protected $description = 'Backtest LLM batch-limit gold strategy with no lookahead (closed-candle triggers only)';

    private TechnicalAnalysisService $ta;

    public function handle(TechnicalAnalysisService $ta, OpenRouterService $llm): int
    {
        $this->ta = $ta;
        $symbol = strtoupper((string) $this->option('symbol'));
        $tf = strtoupper((string) $this->option('timeframe'));

        $latest = Candle::where('symbol', $symbol)->where('timeframe', $tf)->max('candle_time');
        if (! $latest) {
            $this->error("No {$symbol} {$tf} candles in DB. Sync first (bot:sync-candles or FetchCandlesJob).");
            return 1;
        }
        $to = $this->option('to') ? (string) $this->option('to') : substr((string) $latest, 0, 10);
        $from = $this->option('from') ? (string) $this->option('from') : date('Y-m-d', strtotime((string) $latest . ' -1 month'));

        // Warmup: one extra lookback window BEFORE `from` so day-1 has a full
        // week of context. Warmup candles never generate trades.
        $tfMin = ['M1' => 1, 'M5' => 5, 'M15' => 15, 'M30' => 30, 'H1' => 60, 'H4' => 240, 'D1' => 1440][$tf] ?? 60;
        $lookbackDays = max(1, (int) $this->option('lookback-days'));
        $lookbackN = (int) round($lookbackDays * 24 * 60 / $tfMin);
        $warmupFrom = date('Y-m-d H:i:s', strtotime($from . ' -' . ($lookbackDays + 1) . ' days'));

        $candles = Candle::where('symbol', $symbol)->where('timeframe', $tf)
            ->where('candle_time', '>=', $warmupFrom)
            ->where('candle_time', '<=', $to . ' 23:59:59')
            ->orderBy('candle_time')->get()
            ->map(fn ($c) => [
                't' => (string) $c->candle_time, 'o' => (float) $c->open,
                'h' => (float) $c->high, 'l' => (float) $c->low, 'c' => (float) $c->close,
            ])->all();

        // First index that may trade: needs full lookback AND >= from.
        $startIdx = null;
        foreach ($candles as $i => $c) {
            if (substr($c['t'], 0, 10) >= $from && $i >= $lookbackN) { $startIdx = $i; break; }
        }
        if ($startIdx === null) {
            $this->error("Not enough {$symbol} {$tf} data for from={$from} + {$lookbackDays}d lookback (have " . count($candles) . ' candles).');
            return 1;
        }

        $decision = strtolower((string) $this->option('decision'));
        if (! in_array($decision, ['pick', 'gate', 'mock'], true)) $decision = 'pick';
        $modelOv = (string) ($this->option('model') ?: '');
        $llmOpts = $modelOv !== '' ? ['model' => $modelOv] : [];
        // Decision layer is always LLM-driven (pick: model chooses everything;
        // gate: deterministic rule proposes, model approves/rejects). No call cap:
        // every evaluation asks the model. Backstop is the OpenRouter daily
        // budget in settings (openrouter.daily_budget), enforced server-side.
        // Decision layer is LLM-driven in pick/gate modes (mock needs no key).
        if ($decision !== 'mock' && ! $llm->isConfigured()) {
            $this->error('OpenRouter API key is not configured (settings: openrouter.api_key). Cannot run without the LLM decision layer.');
            return 1;
        }

        $expiry = max(1, (int) $this->option('expiry'));
        $cooldown = max(0, (int) $this->option('cooldown'));
        $minConf = (float) $this->option('min-confidence');
        $balance = $equity = (float) $this->option('balance');
        $peak = $balance;
        $riskPct = (float) $this->option('risk-pct');
        $fallbackVol = (float) $this->option('volume');
        $slAtr = (float) $this->option('sl-atr');
        $tpAtr = (float) $this->option('tp-atr');
        $maxHold = max(1, (int) $this->option('max-hold'));

        // Tick spec for XAUUSD-style P&L (same formula as PaperBrokerService::pnl).
        $spec = DB::table('trading_symbols')->where('symbol', $symbol)
            ->select(['tick_size', 'tick_value', 'spread_pips', 'pip_size', 'commission_per_lot'])->first();
        $tickSize = $spec ? (float) $spec->tick_size : 0.01;
        $tickValue = $spec ? (float) $spec->tick_value : 1.0;
        $spreadPips = $spec ? (float) $spec->spread_pips : 0.0;
        $pipSize = $spec && (float) $spec->pip_size > 0 ? (float) $spec->pip_size : 0.1;
        if ($this->option('spread-pips') !== null) $spreadPips = (float) $this->option('spread-pips');
        if ($this->option('pip-size') !== null && (float) $this->option('pip-size') > 0) $pipSize = (float) $this->option('pip-size');
        $commPerLot = $spec ? (float) $spec->commission_per_lot : 0.0;
        // Round-trip execution cost per trade: full spread + commission both sides.
        $costFor = function (float $vol) use ($spreadPips, $pipSize, $tickSize, $tickValue, $commPerLot): float {
            $spreadDist = $spreadPips * $pipSize;
            $spreadCost = $tickSize > 0 ? ($spreadDist / $tickSize) * $tickValue * $vol : 0.0;
            return round($spreadCost + $commPerLot * $vol * 2, 2);
        };
        $pnlFor = fn (string $dir, float $entry, float $exit, float $vol): float => $this->pnl($dir, $entry, $exit, $vol, $tickSize, $tickValue);

        $exitMode = strtolower((string) $this->option('exit-mode')) === 'fixed' ? 'fixed' : 'trail';
        $trailAtr = max(0.0, (float) $this->option('trail-atr'));
        if ($exitMode === 'trail' && $trailAtr <= 0) $exitMode = 'fixed'; // trail needs a distance

        // Multi-TF candidate flow: a faster TF proposes setups, decisions
        // (entries, SL/TP, exits) stay on the analysis TF. Null = same TF.
        $candTf = strtoupper((string) ($this->option('candidate-tf') ?: ''));
        if ($candTf === $tf) $candTf = '';
        $candCandles = []; $candLookbackN = 0; $candPtr = 0;
        // Monthly regime switch: trade a calendar month only if the trailing
        // regime-TF ADX (closed candles, strictly before the month) clears the
        // threshold. Sit-out months: pending batches cancelled, open positions
        // still managed to their natural exit, no new evaluations.
        $regimeTf = strtoupper((string) ($this->option('regime-tf') ?: ''));
        $regimeAdx = (float) $this->option('regime-adx');
        $regimeLbDays = max(5, (int) $this->option('regime-lookback-days'));
        $regCandles = [];
        if ($regimeTf !== '') {
            $regCandles = Candle::where('symbol', $symbol)->where('timeframe', $regimeTf)
                ->where('candle_time', '<=', $to . ' 23:59:59')
                ->orderBy('candle_time')->get()
                ->map(fn ($c) => ['t' => (string) $c->candle_time, 'h' => (float) $c->high, 'l' => (float) $c->low, 'c' => (float) $c->close])->all();
            if (count($regCandles) < 60) { $this->error("Not enough {$symbol} {$regimeTf} candles for regime reads."); return 1; }
        }
        $regimeLog = []; $curMonth = null; $tradeMonth = true;
        if ($candTf !== '') {
            $candMin = ['M1' => 1, 'M5' => 5, 'M15' => 15, 'M30' => 30, 'H1' => 60, 'H4' => 240, 'D1' => 1440][$candTf] ?? 0;
            if ($candMin <= 0) { $this->error("Unsupported --candidate-tf={$candTf}."); return 1; }
            $candLookbackN = (int) round($lookbackDays * 24 * 60 / $candMin);
            $candCandles = Candle::where('symbol', $symbol)->where('timeframe', $candTf)
                ->where('candle_time', '<=', $to . ' 23:59:59')
                ->orderBy('candle_time')->get()
                ->map(fn ($c) => ['t' => (string) $c->candle_time, 'c' => (float) $c->close])->all();
            if (count($candCandles) < $candLookbackN) { $this->error("Not enough {$symbol} {$candTf} candles for {$lookbackDays}d candidate lookback."); return 1; }
        }

        $this->info("GOLD LLM BACKTEST {$symbol} {$tf} | range {$from}..{$to} | lookback {$lookbackDays}d (~{$lookbackN} candles) | warmup from {$warmupFrom}");
        $modelName = $modelOv !== '' ? $modelOv : $llm->modelFor((string) $this->option('agent'));
        $this->info('Mode: LLM decision=' . $decision . ' (' . $modelName . '), every entry LLM-approved' .
            " | expiry {$expiry} | cooldown {$cooldown} | minConf {$minConf} | exit {$exitMode}" . ($exitMode === 'fixed' ? " SL {$slAtr}xATR TP {$tpAtr}xATR" : " trail {$trailAtr}xATR") . " | risk {$riskPct}% | start \${$balance}");

        $orders = [];       // pending batch: [['dir','entry','sl','tp','vol','placed_idx','expires_idx']]
        $pos = null;        // open position
        $nextEvalIdx = $startIdx;
        $llmCalls = 0; $holds = 0; $filtered = 0; $brakes = 0; $consecLoss = 0;
        $lossBrake = max(0, (int) $this->option('loss-brake'));
        $lossCooldown = max(0, (int) $this->option('loss-cooldown'));
        $trades = [];
        $evalLog = [];

        for ($i = $startIdx; $i < count($candles); $i++) {
            $c = $candles[$i]; // JUST-CLOSED candle: only legal information source.

            // Monthly regime switch: decided once, on the month's first candle,
            // from trailing regime-TF closes strictly BEFORE this month.
            if ($regimeTf !== '') {
                $m = substr($c['t'], 0, 7);
                if ($m !== $curMonth) {
                    $curMonth = $m;
                    $cutoff = $m . '-01 00:00:00';
                    $past = array_values(array_filter($regCandles, fn ($r) => $r['t'] < $cutoff));
                    $winDays = array_slice($past, -$regimeLbDays * 6); // ~6 H4 candles/day
                    $adx = count($winDays) >= 40
                        ? $this->ta->adx(array_column($winDays, 'h'), array_column($winDays, 'l'), array_column($winDays, 'c'))
                        : null;
                    $tradeMonth = $adx !== null && $adx >= $regimeAdx;
                    $regimeLog[] = ['month' => $m, 'adx' => $adx, 'trade' => $tradeMonth];
                    $this->line(sprintf('[%s] REGIME %s H4-ADX(30d)=%s -> %s', $c['t'], $m, $adx ?? 'n/a', $tradeMonth ? 'TRADE' : 'SIT OUT'));
                    if (! $tradeMonth && $orders) {
                        $this->line(sprintf('[%s] REGIME cancelled %d pending order(s) (sit-out month)', $c['t'], count($orders)));
                        $orders = [];
                    }
                }
            }

            // --- 1. Manage open position on closed candle (SL wins ties). ---
            if ($pos !== null) {
                $slHit = $pos['dir'] === 'BUY' ? $c['l'] <= $pos['sl'] : $c['h'] >= $pos['sl'];
                // Trail mode has no fixed TP — the trailing SL is the exit.
                $tpHit = $exitMode === 'fixed' && $pos['tp'] !== null
                    ? ($pos['dir'] === 'BUY' ? $c['h'] >= $pos['tp'] : $c['l'] <= $pos['tp'])
                    : false;
                $held = $i - $pos['fill_idx'];
                $exit = null; $reason = null;
                if ($slHit && $tpHit) { $exit = $pos['sl']; $reason = 'STOP_LOSS*'; }
                elseif ($slHit) { $exit = $pos['sl']; $reason = 'STOP_LOSS'; }
                elseif ($tpHit) { $exit = $pos['tp']; $reason = 'TAKE_PROFIT'; }
                elseif ($held >= $maxHold) { $exit = $c['c']; $reason = 'MAX_HOLD'; } // close-price trigger
                if ($exit !== null) {
                    $gross = $pnlFor($pos['dir'], $pos['entry'], $exit, $pos['vol']);
                    $costs = $costFor($pos['vol']);
                    $p = round($gross - $costs, 2);
                    $pct = $equity > 0 ? $p / $equity * 100 : 0;
                    $equity += $p; $peak = max($peak, $equity);
                    $trades[] = [...$pos, 'exit' => $exit, 'reason' => $reason, 'exit_t' => $c['t'], 'pnl' => round($p, 2), 'pct' => round($pct, 4), 'costs' => $costs, 'gross' => round($gross, 2)];
                    $this->line(sprintf(
                        '[%s] CLOSE %s %s entry=%.2f exit=%.2f gross=%+.2f costs=%.2f pnl=%+.2f (%+.3f%%) reason=%s held=%d eq=%.2f',
                        $c['t'], $pos['dir'], $symbol, $pos['entry'], $exit, $gross, $costs, $p, $pct, $reason, $held, $equity
                    ));
                    $pos = null;
                    $nextEvalIdx = $this->registerClose($p, $i, $lossBrake, $lossCooldown, $consecLoss, $brakes);
                    continue; // never open new risk on the same candle that closed a trade
                }

                // Still open: breakeven protection, then ATR trailing (both tighten
                // SL for FUTURE candles only — this candle's outcome above is untouched).
                $beR = (float) $this->option('breakeven-r');
                if ($beR > 0 && empty($pos['be_moved'])) {
                    $risk = abs($pos['entry'] - $pos['sl']);
                    $fav = $pos['dir'] === 'BUY' ? ($c['c'] - $pos['entry']) : ($pos['entry'] - $c['c']);
                    if ($risk > 0 && $fav >= $beR * $risk) {
                        $pos['sl'] = $pos['entry'];
                        $pos['be_moved'] = true;
                        $this->line(sprintf('[%s] BREAKEVEN %s SL -> entry %.2f (+%.2fR)', $c['t'], $pos['dir'], $pos['entry'], $fav / $risk));
                    }
                }
                if ($exitMode === 'trail' && $trailAtr > 0 && isset($pos['atr'])) {
                    // Peak excursion uses this closed candle's extreme (known, no lookahead).
                    $peak = $pos['dir'] === 'BUY' ? max($pos['peak'] ?? $pos['entry'], $c['h']) : min($pos['peak'] ?? $pos['entry'], $c['l']);
                    $pos['peak'] = $peak;
                    $candidate = $pos['dir'] === 'BUY' ? $peak - $trailAtr * $pos['atr'] : $peak + $trailAtr * $pos['atr'];
                    $tightens = $pos['dir'] === 'BUY' ? $candidate > $pos['sl'] : $candidate < $pos['sl'];
                    if ($tightens) {
                        $pos['sl'] = round($candidate, 2);
                        $this->line(sprintf('[%s] TRAIL %s SL -> %.2f (peak %.2f)', $c['t'], $pos['dir'], $pos['sl'], $peak));
                    }
                }
            }

            // --- 2. Manage pending batch: fills, then expiry. ---
            if ($pos === null && $orders) {
                $filledKey = null;
                foreach ($orders as $k => $o) {
                    // Limit fill iff entry inside this closed candle's range.
                    if ($c['l'] <= $o['entry'] && $o['entry'] <= $c['h']) { $filledKey = $k; break; } // FIFO: lowest id first
                }
                if ($filledKey !== null) {
                    $f = $orders[$filledKey];
                    $cancelled = count($orders) - 1;
                    $orders = [];
                    // Fill-candle realism: a limit fills DURING the candle, so if the
                    // same closed candle also spanned the stop, the stop goes first
                    // (conservative — mirrors PaperBrokerService's ambiguous-fill rule).
                    $slOnFill = $f['dir'] === 'BUY' ? $c['l'] <= $f['sl'] : $c['h'] >= $f['sl'];
                    if ($slOnFill) {
                        $gross = $pnlFor($f['dir'], $f['entry'], $f['sl'], $f['vol']);
                        $costs = $costFor($f['vol']);
                        $p = round($gross - $costs, 2);
                        $pct = $equity > 0 ? $p / $equity * 100 : 0;
                        $equity += $p;
                        $trades[] = [...$f, 'fill_t' => $c['t'], 'fill_idx' => $i, 'exit' => $f['sl'], 'reason' => 'STOP_LOSS_FILL', 'exit_t' => $c['t'], 'pnl' => $p, 'pct' => round($pct, 4), 'costs' => $costs, 'gross' => round($gross, 2)];
                        $this->line(sprintf('[%s] FILL+STOP %s %s @ %.2f -> SL %.2f same candle, pnl=%+.2f (cancelled %d sibling(s))', $c['t'], $f['dir'], $symbol, $f['entry'], $f['sl'], $p, $cancelled));
                        $nextEvalIdx = $this->registerClose($p, $i, $lossBrake, $lossCooldown, $consecLoss, $brakes);
                        continue;
                    }
                    $pos = [...$f, 'fill_t' => $c['t'], 'fill_idx' => $i];
                    $this->line(sprintf(
                        '[%s] FILL %s %s @ %.2f (cancelled %d sibling(s)) SL=%.2f TP=%s vol=%.2f',
                        $c['t'], $f['dir'], $symbol, $f['entry'], $cancelled, $f['sl'],
                        $f['tp'] === null ? 'trail' : number_format($f['tp'], 2), $f['vol']
                    ));
                    continue; // position management starts next candle
                }
                if ($i >= $orders[0]['expires_idx']) {
                    $this->line(sprintf('[%s] EXPIRE batch of %d unfilled order(s) after %d candles -> re-evaluate in %d', $c['t'], count($orders), $expiry, $cooldown));
                    $orders = [];
                    $nextEvalIdx = $i + $cooldown;
                    continue;
                }
                continue; // batch still alive: no new evaluation
            }

            // --- 3. Flat & no live batch: evaluate (or wait for cooldown). ---
            // Regime sit-out months skip evaluation entirely (open positions
            // are still managed above; pending batches were cancelled).
            if ($pos === null && ! $orders && $i >= $nextEvalIdx && $tradeMonth) {
                $window = array_slice($candles, $i - $lookbackN + 1, $lookbackN); // <= i only
                if ($decision === 'mock') {
                    // Original deterministic rule trading alone: same EMA/RSI
                    // proposer, auto-approved at fixed confidence. Zero LLM cost.
                    $cand = null;
                    if ($candTf !== '') {
                        while ($candPtr < count($candCandles) && $candCandles[$candPtr]['t'] <= $c['t']) $candPtr++;
                        $fast = array_column(array_slice($candCandles, max(0, $candPtr - $candLookbackN), $candLookbackN), 'c');
                        if (count($fast) >= $candLookbackN) $cand = $this->proposeCandidate($window, $fast);
                    } else {
                        $cand = $this->proposeCandidate($window);
                    }
                    if ($cand === null) {
                        $holds++;
                        $nextEvalIdx = $i + $cooldown;
                        continue;
                    }
                    $sig = ['action' => $cand['dir'], 'confidence' => 0.7, 'rationale' => 'mock: ' . $cand['why'],
                        'entries' => $cand['entries'], 'sl_atr' => $cand['sl_atr'], 'tp_atr' => $cand['tp_atr']];
                } elseif ($decision === 'gate') {
                    // Deterministic rule proposes; the LLM disposes (approve/reject
                    // with regime context). No candidate -> HOLD without spending a call.
                    $cand = null;
                    if ($candTf !== '') {
                        // Advance pointer over faster-TF closes at/before this decision candle.
                        while ($candPtr < count($candCandles) && $candCandles[$candPtr]['t'] <= $c['t']) $candPtr++;
                        $fast = array_column(array_slice($candCandles, max(0, $candPtr - $candLookbackN), $candLookbackN), 'c');
                        if (count($fast) >= $candLookbackN) $cand = $this->proposeCandidate($window, $fast);
                    } else {
                        $cand = $this->proposeCandidate($window);
                    }
                    if ($cand === null) {
                        $holds++;
                        $nextEvalIdx = $i + $cooldown;
                        continue;
                    }
                    $gate = $this->askGate($llm, $symbol, $tf, $window, $c, $cand, $this->option('agent'), $llmOpts);
                    $llmCalls++;
                    $evalLog[] = ['t' => $c['t'], 'close' => $c['c'], 'signal' => $gate + ['candidate' => $cand['dir']]];
                    $gateScore = (float) ($gate['score'] ?? 0);
                    $minScore = (float) $this->option('gate-score');
                    if ($gateScore < $minScore || ($gate['confidence'] ?? 0) < $minConf) {
                        $holds++;
                        $this->line(sprintf('[%s] GATE-REJECT %s score=%.0f conf=%.2f (%s) -> wait %d', $c['t'], $cand['dir'], $gateScore, $gate['confidence'] ?? 0, substr((string) ($gate['rationale'] ?? ''), 0, 90), $cooldown));
                        $nextEvalIdx = $i + $cooldown;
                        continue;
                    }
                    // Approved: LLM-adjusted grid when complete, else the candidate grid.
                    $sig = ['action' => $cand['dir'], 'confidence' => $gate['confidence'],
                        'rationale' => 'gate-approved: ' . ($gate['rationale'] ?? ''),
                        'entries' => $gate['entries'] ?? $cand['entries'], 'sl_atr' => $gate['sl_atr'] ?? $cand['sl_atr'],
                        'tp_atr' => $gate['tp_atr'] ?? $cand['tp_atr']];
                    $this->line(sprintf('[%s] GATE-APPROVE %s score=%.0f conf=%.2f (%s)', $c['t'], $cand['dir'], $gateScore, $gate['confidence'] ?? 0, substr((string) ($gate['rationale'] ?? ''), 0, 90)));
                } else {
                    $sig = $this->askLlm($llm, $symbol, $tf, $window, $c, $this->option('agent'), $llmOpts);
                    $llmCalls++;
                }
                $evalLog[] = ['t' => $c['t'], 'close' => $c['c'], 'signal' => $sig];

                if (($sig['action'] ?? 'HOLD') === 'HOLD' || ($sig['confidence'] ?? 0) < $minConf) {
                    $holds++;
                    $this->line(sprintf(
                        '[%s] EVAL close=%.2f action=%s conf=%.2f -> not meaningful, wait %d candle(s)',
                        $c['t'], $c['c'], $sig['action'] ?? 'HOLD', $sig['confidence'] ?? 0, $cooldown
                    ));
                    $nextEvalIdx = $i + $cooldown;
                    continue;
                }

                // Meaningful signal -> trend gate -> build batch of limit orders around close.
                $dir = strtoupper($sig['action']); // BUY or SELL
                if ((bool) ((int) $this->option('trend-filter'))) {
                    $trend = $this->ta->trend(array_column($window, 'c')); // window ends at this close: no lookahead
                    $against = ($trend === 'UP' && $dir === 'SELL') || ($trend === 'DOWN' && $dir === 'BUY');
                    if ($against) {
                        $filtered++;
                        $this->line(sprintf(
                            '[%s] FILTERED %s conf=%.2f contradicts window trend=%s -> wait %d candle(s)',
                            $c['t'], $dir, $sig['confidence'], $trend, $cooldown
                        ));
                        $nextEvalIdx = $i + $cooldown;
                        continue;
                    }
                }
                $atr = $this->atrOf($window);
                // SL/TP + entry grid come from the signal (LLM-decided) when present,
                // clamped to sane bounds; otherwise fall back to CLI defaults.
                // Offsets are ATR multiples on the pullback side (BUY below / SELL above close).
                // SL/TP + entry grid are LLM-decided (required). Clamped to sane
                // bounds; a missing/invalid grid means NO TRADE (never defaults).
                if (! is_array($sig['entries'] ?? null) || ! ($sig['entries'] ?? []) || ! is_numeric($sig['sl_atr'] ?? null)) {
                    $holds++;
                    $this->line(sprintf('[%s] EVAL %s conf=%.2f incomplete grid -> no trade, wait %d', $c['t'], $sig['action'] ?? '?', $sig['confidence'] ?? 0, $cooldown));
                    $nextEvalIdx = $i + $cooldown;
                    continue;
                }
                $slAtrEff = $this->clamp((float) $sig['sl_atr'], 0.5, 3.0);
                $tpAtrEff = $exitMode === 'fixed' ? $this->clamp((float) ($sig['tp_atr'] ?? $tpAtr), 1.0, 5.0) : null;
                $offsets = $sig['entries'];
                $offsets = array_values(array_unique(array_map(fn ($o) => $this->clamp((float) $o, 0.1, 2.0), $offsets)));
                sort($offsets); // ascending => closest to close first: first-touch order
                $slD = $slAtrEff * $atr; $tpD = $tpAtrEff !== null ? $tpAtrEff * $atr : null;
                $vol = $this->sizeByRisk($equity, $riskPct, $slD, $tickSize, $tickValue, $fallbackVol);
                $orders = [];
                foreach ($offsets as $off) {
                    $e = round($dir === 'BUY' ? $c['c'] - $off * $atr : $c['c'] + $off * $atr, 2);
                    // Entry-side validation: pullback limits must sit on their own side of the close.
                    if ($dir === 'BUY' && $e >= $c['c']) continue;
                    if ($dir === 'SELL' && $e <= $c['c']) continue;
                    $orders[] = [
                        'dir' => $dir, 'entry' => $e, 'vol' => $vol,
                        'sl' => round($dir === 'BUY' ? $e - $slD : $e + $slD, 2),
                        'tp' => $tpD !== null ? round($dir === 'BUY' ? $e + $tpD : $e - $tpD, 2) : null,
                        'atr' => round($atr, 4), 'peak' => $e,
                        'placed_t' => $c['t'], 'placed_idx' => $i, 'expires_idx' => $i + $expiry,
                        'conf' => $sig['confidence'], 'why' => substr((string) ($sig['rationale'] ?? ''), 0, 160),
                    ];
                }
                if (! $orders) {
                    $this->line(sprintf('[%s] SIGNAL %s produced no valid entries -> wait %d', $c['t'], $dir, $cooldown));
                    $nextEvalIdx = $i + $cooldown;
                    continue;
                }
                $this->line(sprintf(
                    '[%s] SIGNAL %s conf=%.2f atr=%.2f sl=%.1fR tp=%s exit=%s -> placed %d LIMITS %s vol=%.2f expiry=%d: %s',
                    $c['t'], $dir, $sig['confidence'], $atr, $slAtrEff, $tpAtrEff ?? 'trail', $exitMode, count($orders), $symbol, $vol, $expiry,
                    implode(' / ', array_map(fn ($o) => number_format($o['entry'], 2), $orders))
                ));
            }
        }

        // Force-close anything still open at the last CLOSE (close-price trigger).
        $last = end($candles);
        if ($pos !== null) {
            $gross = $pnlFor($pos['dir'], $pos['entry'], $last['c'], $pos['vol']);
            $costs = $costFor($pos['vol']);
            $p = round($gross - $costs, 2);
            $pct = $equity > 0 ? $p / $equity * 100 : 0;
            $equity += $p;
            $trades[] = [...$pos, 'exit' => $last['c'], 'reason' => 'END_OF_DATA', 'exit_t' => $last['t'], 'pnl' => round($p, 2), 'pct' => round($pct, 4), 'costs' => $costs ?? 0, 'gross' => round($gross ?? $p, 2)];
            $this->line(sprintf('[%s] CLOSE (end of data) pnl=%+.2f (%+.3f%%)', $last['t'], $p, $pct));
        }
        if ($orders) $this->line(sprintf('Cancelled %d stale order(s) at end of data.', count($orders)));

        // --- Summary (percentages on equity). ---
        $net = array_sum(array_column($trades, 'pnl'));
        $grossTot = array_sum(array_column($trades, 'gross'));
        $costTot = array_sum(array_column($trades, 'costs'));
        $netPct = $balance > 0 ? $net / $balance * 100 : 0;
        $wins = count(array_filter($trades, fn ($t) => $t['pnl'] > 0));
        $n = count($trades);
        $avgWin = $this->avg(array_filter(array_column($trades, 'pnl'), fn ($v) => $v > 0));
        $avgLoss = $this->avg(array_filter(array_column($trades, 'pnl'), fn ($v) => $v <= 0));
        // Max drawdown on equity curve (percent of running peak).
        $eq = $balance; $pk = $balance; $mdd = 0;
        foreach ($trades as $t) { $eq += $t['pnl']; $pk = max($pk, $eq); $mdd = min($mdd, ($eq - $pk) / $pk * 100); }

        $this->newLine();
        $this->info('================ BACKTEST SUMMARY ================');
        foreach ([
            "Symbol / TF      : {$symbol} / {$tf}  ({$from}..{$to})",
            sprintf('Trades / Wins    : %d / %d (win rate %.1f%%)', $n, $wins, $n ? $wins / $n * 100 : 0),
            sprintf('Net P&L          : %+.2f ( %+.2f%% of start %.2f )  [gross %+.2f - costs %.2f]', $net, $netPct, $balance, $grossTot, $costTot),
            sprintf('Final equity     : %.2f   Max DD: %.2f%%', $equity, $mdd),
            sprintf('Avg win / loss   : %+.2f / %+.2f   Expectancy/trade: %+.2f', $avgWin, $avgLoss, $n ? $net / $n : 0),
            "Evals / HOLDs / FILTERED / BRAKES: " . count($evalLog) . " / {$holds} / {$filtered} / {$brakes}   LLM calls: {$llmCalls}",
        ] as $row) $this->line($row);

        $report = [
            'symbol' => $symbol, 'timeframe' => $tf, 'from' => $from, 'to' => $to,
            'params' => $this->options(), 'mode' => 'llm',
            'starting_balance' => $balance, 'final_equity' => round($equity, 2),
            'net' => round($net, 2), 'net_pct' => round($netPct, 3),
            'trades' => $n, 'wins' => $wins,
            'win_rate_pct' => $n ? round($wins / $n * 100, 2) : 0,
            'max_drawdown_pct' => round($mdd, 3),
            'expectancy' => $n ? round($net / $n, 2) : 0,
            'evaluations' => count($evalLog), 'holds' => $holds, 'llm_calls' => $llmCalls,
            'filtered' => $filtered, 'brakes' => $brakes, 'regime_log' => $regimeLog,
            'gross' => round($grossTot, 2), 'costs' => round($costTot, 2),
            'eval_log' => $evalLog,
            'trade_list' => $trades,
        ];
        $path = (string) ($this->option('report') ?: storage_path('logs/gold-llm-backtest-' . date('Ymd-His') . '.json'));
        @file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT));
        $this->info("JSON report -> {$path}");

        $txtPath = (string) ($this->option('txt-report') ?: preg_replace('/\.json$/', '.txt', $path));
        @file_put_contents($txtPath, $this->txtReport($report, $symbol, $tf, $from, $to, $balance, $equity, $net, $netPct, $n, $wins, $mdd));
        $this->info("TXT trade report -> {$txtPath}");

        return 0;
    }

    // ---------- pricing / sizing ----------

    private function pnl(string $dir, float $entry, float $exit, float $vol, float $tickSize, float $tickValue): float
    {
        if ($tickSize <= 0) return 0.0;
        $ticks = ($exit - $entry) / $tickSize;
        if ($dir === 'SELL') $ticks = -$ticks;
        return round($ticks * $tickValue * $vol, 2);
    }

    private function sizeByRisk(float $equity, float $riskPct, float $slDist, float $tickSize, float $tickValue, float $fallback): float
    {
        if ($slDist <= 0 || $tickSize <= 0 || $tickValue <= 0) return $fallback;
        $riskMoney = $equity * $riskPct / 100;
        $lossPerLot = ($slDist / $tickSize) * $tickValue;
        if ($lossPerLot <= 0) return $fallback;
        return round(max(0.01, min(5.0, $riskMoney / $lossPerLot)), 2);
    }

    private function atrOf(array $window): float
    {
        $n = count($window);
        $trs = [];
        for ($i = max(1, $n - 14); $i < $n; $i++) {
            $trs[] = max(
                $window[$i]['h'] - $window[$i]['l'],
                abs($window[$i]['h'] - $window[$i - 1]['c']),
                abs($window[$i]['l'] - $window[$i - 1]['c'])
            );
        }
        return $trs ? array_sum($trs) / count($trs) : 0.0;
    }

    // ---------- signals ----------

    /**
     * Deterministic candidate proposer for gate mode. Same EMA/RSI logic the
     * old mock used — but here it only PROPOSES. The LLM approves or rejects.
     * Returns null when there is no setup (no LLM call is spent).
     * With --candidate-tf, the rule runs on the faster TF (closes at/before
     * the decision candle — no lookahead); otherwise on the analysis window.
     */
    private function proposeCandidate(array $window, ?array $fastCloses = null): ?array
    {
        $closes = $fastCloses ?? array_column($window, 'c');
        $rsi = $this->ta->rsi($closes, 14);
        $emaF = $this->ta->ema($closes, 12);
        $emaS = $this->ta->ema($closes, 26);
        if ($rsi === null || $emaF === null || $emaS === null) return null;
        if ($emaF > $emaS && $rsi < 70) return ['dir' => 'BUY', 'entries' => [0.25, 0.5, 0.75], 'sl_atr' => 1.5, 'tp_atr' => 3.0, 'why' => "emaF>emaS rsi={$rsi}"];
        if ($emaF < $emaS && $rsi > 30) return ['dir' => 'SELL', 'entries' => [0.25, 0.5, 0.75], 'sl_atr' => 1.5, 'tp_atr' => 3.0, 'why' => "emaF<emaS rsi={$rsi}"];
        return null;
    }

    /**
     * Gatekeeper prompt: a rule-based candidate exists — approve or reject it
     * using regime context. Runs on CLOSED candles only.
     */
    private function askGate(OpenRouterService $llm, string $symbol, string $tf, array $window, array $now, array $cand, string $agent, array $llmOpts = []): array
    {
        $closes = array_column($window, 'c');
        $atr = $this->atrOf($window);
        $hour = (int) gmdate('H', strtotime($now['t']));
        $session = ($hour >= 12 && $hour < 17) ? 'New-York' : (($hour >= 7 && $hour < 12) ? 'London' : (($hour >= 0 && $hour < 7) ? 'Asian' : 'off-hours'));
        $facts = [
            'symbol' => $symbol, 'timeframe' => $tf,
            'last_closed_candle' => ['time_utc' => $now['t'], 'close' => $now['c']],
            'session_utc' => $session,
            'candidate' => ['direction' => $cand['dir'], 'rule' => $cand['why'], 'entries_atr' => $cand['entries'], 'sl_atr' => $cand['sl_atr']],
            'regime' => [
                'sma20' => $this->ta->sma($closes, 20), 'sma50' => $this->ta->sma($closes, 50),
                'rsi14' => $this->ta->rsi($closes, 14), 'atr14' => $atr,
                'adx14' => $this->ta->adx(array_column($window, 'h'), array_column($window, 'l'), $closes),
                'trend' => $this->ta->trend($closes),
                'dist_to_window_high_atr' => $atr > 0 ? round((max($closes) - $now['c']) / $atr, 2) : null,
                'dist_to_window_low_atr' => $atr > 0 ? round(($now['c'] - min($closes)) / $atr, 2) : null,
            ],
            'task' => 'The rule proposes ' . $cand['dir'] . ' because "' . $cand['why'] . '". Veto it if: the move already exhausted into window high/low, ADX shows chop, session is dead (Asian/off-hours), or risk/reward to the nearest window extreme is poor. Otherwise APPROVE.',
        ];
        $system = 'You are the risk filter for an XAUUSD intraday system. A deterministic rule proposes trades often; your job is to SCORE each one 0-100 so only the strongest survive. '
            . 'Score 85+: exceptional (trend + session + level + momentum all align). 70-84: solid, tradeable. 60-69: acceptable. Below 60: flawed — exhausted move, chop, dead session, or poor distance to the nearest window extreme. '
            . 'Calibrate honestly across the full range; most candidates should land 35-75, not cluster at one number. '
            . 'Reply with JSON ONLY: {"score":0-100,"confidence":0.0-1.0,"rationale":"<40 words>"'
            . ',"entries":[0.25,0.5,0.75],"sl_atr":1.5}. entries/sl_atr optional: include ONLY to override the candidate grid with a better one.';
        $res = $llm->chatJson($agent, $system, $facts, $llmOpts);
        if (! ($res['ok'] ?? false) || ! isset($res['data']['score'])) {
            return ['score' => 0, 'confidence' => 0.0, 'rationale' => 'llm-error: ' . substr((string) ($res['error'] ?? 'unknown'), 0, 120)];
        }
        $d = $res['data'];
        return ['score' => max(0, min(100, (float) ($d['score'] ?? 0))), 'confidence' => (float) ($d['confidence'] ?? 0), 'rationale' => (string) ($d['rationale'] ?? ''),
            'entries' => $d['entries'] ?? null, 'sl_atr' => $d['sl_atr'] ?? null, 'tp_atr' => $d['tp_atr'] ?? null];
    }

    /**
     * Gold prediction prompt. Context = ONLY the trailing window of CLOSED
     * candles ending at the just-closed candle (no future data is ever sent).
     * Every order the backtest places comes from this response — there is no
     * rule-based fallback: an incomplete response means HOLD.
     */
    private function askLlm(OpenRouterService $llm, string $symbol, string $tf, array $window, array $now, string $agent, array $llmOpts = []): array
    {
        $closes = array_column($window, 'c');
        $atr = $this->atrOf($window);
        $hour = (int) gmdate('H', strtotime($now['t']));
        $session = ($hour >= 12 && $hour < 17) ? 'New-York' : (($hour >= 7 && $hour < 12) ? 'London' : (($hour >= 0 && $hour < 7) ? 'Asian' : 'off-hours'));
        $facts = [
            'symbol' => $symbol, 'timeframe' => $tf,
            'last_closed_candle' => ['time_utc' => $now['t'], 'open' => $now['o'], 'high' => $now['h'], 'low' => $now['l'], 'close' => $now['c']],
            'session_utc' => $session,
            'context' => 'Trailing ' . count($window) . ' CLOSED ' . $tf . ' candles, oldest first, ending at last_closed_candle. Nothing after that time exists.',
            'features' => [
                'sma20' => $this->ta->sma($closes, 20), 'sma50' => $this->ta->sma($closes, 50),
                'rsi14' => $this->ta->rsi($closes, 14), 'atr14' => $atr,
                'adx14' => $this->ta->adx(array_column($window, 'h'), array_column($window, 'l'), $closes),
                'trend' => $this->ta->trend($closes),
                'window_high' => max($closes), 'window_low' => min($closes),
                'pct_from_high_atr' => $atr > 0 ? round((max($closes) - $now['c']) / $atr, 2) : null,
                'pct_from_low_atr' => $atr > 0 ? round(($now['c'] - min($closes)) / $atr, 2) : null,
                'last_5_closes' => array_slice($closes, -5),
            ],
            'candles_ohlc' => array_map(fn ($c) => [$c['t'], $c['o'], $c['h'], $c['l'], $c['c']], $window),
        ];
        $system = 'You are a discretionary XAUUSD (spot gold) intraday trader. '
            . 'You receive ONLY closed-candle history ending at the stated last_closed_candle; predict the NEXT move over roughly the next 24 hours. '
            . 'STRICT DIRECTION RULE: compare last close to sma20/sma50. If close is BELOW both (downtrend), you may ONLY answer SELL (short the rallies) or HOLD — never BUY. '
            . 'If close is ABOVE both (uptrend), you may ONLY answer BUY (buy the dips) or HOLD — never SELL. '
            . 'Counter-trend trades are forbidden unless you see an explicit reversal candle (long wick rejecting window_high/low on rising volume) AND rsi14 beyond 70/30 — state both facts in the rationale. '
            . 'Gold trends hard, reverses violently at session opens (London 07:00, New-York 12:00 UTC) and round numbers, and chops in Asia — in chop, HOLD. '
            . 'Express directional trades as pullback LIMIT entries on YOUR side of the current close (BUY limits BELOW close, SELL limits ABOVE close): entries are ATR multiples of distance from close, 0.1-2.0. '
            . 'Give sl_atr (stop distance per entry, 0.5-3.0) and tp_atr (1.0-5.0, used only in fixed-exit mode). '
            . 'Confidence must reflect stacked confluence (trend + session + level + momentum): lone-RSI setups stay below 0.55, i.e. HOLD. '
            . 'Reply with JSON ONLY, no prose: '
            . '{"action":"BUY"|"SELL"|"HOLD","confidence":0.0-1.0,"rationale":"<40 words>","entries":[0.25,0.5,0.75],"sl_atr":1.5,"tp_atr":3.0}. '
            . 'If action is BUY or SELL, entries and sl_atr are MANDATORY.';
        $res = $llm->chatJson($agent, $system, $facts, $llmOpts);
        if (! ($res['ok'] ?? false) || ! isset($res['data']['action'])) {
            return ['action' => 'HOLD', 'confidence' => 0.0, 'rationale' => 'llm-error: ' . substr((string) ($res['error'] ?? 'unknown'), 0, 120)];
        }
        $d = $res['data'];
        $action = strtoupper((string) ($d['action'] ?? 'HOLD'));
        if (! in_array($action, ['BUY', 'SELL', 'HOLD'], true)) $action = 'HOLD';
        return [
            'action' => $action, 'confidence' => (float) ($d['confidence'] ?? 0), 'rationale' => (string) ($d['rationale'] ?? ''),
            'entries' => $d['entries'] ?? null, 'sl_atr' => $d['sl_atr'] ?? null, 'tp_atr' => $d['tp_atr'] ?? null,
        ];
    }

    private function clamp(float $v, float $lo, float $hi): float
    {
        return max($lo, min($hi, $v));
    }

    /**
     * Loss-streak brake: a losing close increments the streak, a winner resets
     * it. At $lossBrake consecutive losses the next evaluation is pushed out
     * $lossCooldown candles and the streak resets. Returns next eval index.
     */
    private function registerClose(float $pnl, int $i, int $lossBrake, int $lossCooldown, int &$consecLoss, int &$brakes): int
    {
        if ($pnl <= 0) { $consecLoss++; } else { $consecLoss = 0; }
        if ($lossBrake > 0 && $consecLoss >= $lossBrake) {
            $brakes++;
            $consecLoss = 0;
            return $i + max(1, $lossCooldown);
        }
        return $i + 1;
    }

    private function avg(array $v): float
    {
        $v = array_values($v);
        return $v ? round(array_sum($v) / count($v), 2) : 0.0;
    }

    /** Human-readable trade-by-trade report. */
    private function txtReport(array $r, string $symbol, string $tf, string $from, string $to,
        float $balance, float $equity, float $net, float $netPct, int $n, int $wins, float $mdd): string
    {
        $L = [];
        $L[] = str_repeat('=', 108);
        $L[] = "GOLD LLM BACKTEST — TRADE-WISE REPORT  {$symbol} {$tf}  {$from} .. {$to}  mode={$r['mode']}";
        $L[] = 'Params: ' . json_encode($r['params']);
        $L[] = str_repeat('=', 108);
        $L[] = sprintf('Start balance : %12.2f', $balance);
        $L[] = sprintf('Final equity  : %12.2f', $equity);
        $L[] = sprintf('Net P&L       : %+12.2f  (%+.3f%% of start)   [gross %+.2f - costs %.2f]',
            $net, $netPct, $r['gross'] ?? 0, $r['costs'] ?? 0);
        $L[] = sprintf('Trades/Wins   : %d / %d  (win rate %.1f%%)   Max DD %.2f%%   Expectancy %+.2f   Evals %d / Holds %d / Filtered %d / Brakes %d',
            $n, $wins, $n ? $wins / $n * 100 : 0, $mdd, $n ? $net / $n : 0,
            $r['evaluations'], $r['holds'], $r['filtered'] ?? 0, $r['brakes'] ?? 0);
        $L[] = str_repeat('-', 108);
        $L[] = sprintf('%-4s %-16s %-4s %9s %9s %6s %9s %9s %9s %8s %-19s %-19s %s',
            '#', 'FILL_TIME', 'DIR', 'ENTRY', 'EXIT', 'VOL', 'GROSS', 'COSTS', 'NET_PNL', 'NET_%', 'SL', 'TP', 'REASON / EXIT_TIME');
        $L[] = str_repeat('-', 108);
        foreach ($r['trade_list'] as $k => $t) {
            $L[] = sprintf('#%-3d %-16s %-4s %9.2f %9.2f %6.2f %+9.2f %9.2f %+9.2f %+7.3f%%  SL %-8.2f TP %-8s %s @ %s%s',
                $k + 1, substr($t['fill_t'], 0, 16), $t['dir'], $t['entry'], $t['exit'], $t['vol'],
                $t['gross'] ?? $t['pnl'], $t['costs'] ?? 0, $t['pnl'], $t['pct'],
                $t['sl'], $t['tp'] === null ? 'trail' : number_format($t['tp'], 2), $t['reason'], substr($t['exit_t'], 0, 16),
                ! empty($t['be_moved']) ? '  [breakeven moved]' : '');
        }
        $L[] = str_repeat('=', 108);
        // Per-direction + per-month rollups.
        foreach (['BUY', 'SELL'] as $d) {
            $dts = array_filter($r['trade_list'], fn ($t) => $t['dir'] === $d);
            $dn = count($dts); $dw = count(array_filter($dts, fn ($t) => $t['pnl'] > 0));
            $L[] = sprintf('%-4s: n=%d wins=%d (%.1f%%) net=%+.2f', $d, $dn, $dw, $dn ? $dw / $dn * 100 : 0, array_sum(array_column($dts, 'pnl')));
        }
        $months = [];
        foreach ($r['trade_list'] as $t) $months[substr($t['exit_t'], 0, 7)][] = $t;
        ksort($months);
        foreach ($months as $m => $mts) {
            $L[] = sprintf('%s: n=%d net=%+.2f', $m, count($mts), array_sum(array_column($mts, 'pnl')));
        }
        $L[] = '';
        return implode(PHP_EOL, $L) . PHP_EOL;
    }
}
