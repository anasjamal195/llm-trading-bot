<?php

namespace App\Services\Risk;

use App\Models\PaperOrder;
use App\Models\PaperPosition;
use App\Models\Setting;
use App\Models\TradeProposal;
use App\Models\TradingAccount;
use App\Models\TradingSymbol;


/**
 * Deterministic risk management. No LLM dependency.
 * All limits configurable via settings; fails closed.
 */
class RiskManagementService
{
    public function get(string $key, $default)
    {
        return Setting::get("risk.{$key}", $default);
    }

    public function killSwitchEngaged(): bool
    {
        return (bool) Setting::get('risk.kill_switch', false);
    }

    /**
     * Validate a trade proposal. Returns ['approved'=>bool,'reasons'=>[],'volume'=>float,'risk_amount'=>float].
     */
    public function validateProposal(array $proposal): array
    {
        $reasons = [];

        if ($this->killSwitchEngaged()) $reasons[] = 'Global kill switch engaged';

        $account = TradingAccount::where('is_active', true)->first();
        if (! $account) $reasons[] = 'No active trading account';
        $equity = $account ? (float) $account->equity : 0;

        $symbol = $proposal['symbol'] ?? '';
        $ts = TradingSymbol::where('symbol', $symbol)->first();
        if (! $ts || ! $ts->is_enabled) $reasons[] = "Symbol {$symbol} not enabled";

        $entry = (float) ($proposal['entry'] ?? 0);
        $sl = (float) ($proposal['stop_loss'] ?? 0);
        $tp = (float) ($proposal['take_profit'] ?? 0);
        $direction = strtoupper($proposal['direction'] ?? '');

        if (! in_array($direction, ['BUY', 'SELL'], true)) $reasons[] = 'Invalid direction';
        if ($entry <= 0 || $sl <= 0 || $tp <= 0) $reasons[] = 'Entry/SL/TP must be positive';
        if ($direction === 'BUY' && ! ($sl < $entry && $tp > $entry)) $reasons[] = 'BUY requires SL<entry<TP';
        if ($direction === 'SELL' && ! ($sl > $entry && $tp < $entry)) $reasons[] = 'SELL requires TP<entry<SL';

        $slDist = abs($entry - $sl);
        $tpDist = abs($tp - $entry);
        $minRR = (float) $this->get('min_risk_reward', 1.5);
        if ($slDist > 0 && ($tpDist / $slDist) < $minRR) $reasons[] = "Risk/reward below minimum {$minRR}";

        // Spread guard.
        if ($ts && $slDist > 0) {
            $maxSpreadPips = (float) $this->get('max_spread_pips', 5);
            $pipSize = $this->pipSize($symbol);
            if (($ts->spread_pips * $pipSize) > ($slDist * 0.25)) $reasons[] = 'Spread too large relative to stop distance';
            if ($ts->spread_pips > $maxSpreadPips) $reasons[] = "Spread {$ts->spread_pips} exceeds max {$maxSpreadPips} pips";
        }

        // Exposure limits.
        $maxOpen = (int) $this->get('max_open_positions', 2);
        $open = PaperPosition::where('status', 'OPEN')->count();
        if ($open >= $maxOpen) $reasons[] = "Max open positions reached ({$maxOpen})";

        $maxPending = (int) $this->get('max_pending_orders', 4);
        $pending = PaperOrder::where('status', 'PENDING')->count();
        if ($pending >= $maxPending) $reasons[] = "Max pending orders reached ({$maxPending})";

        $maxDailyTrades = (int) $this->get('max_daily_trades', 10);
        $todayTrades = PaperOrder::whereDate('created_at', today())->count();
        if ($todayTrades >= $maxDailyTrades) $reasons[] = 'Max daily trades reached';

        $maxDailyLoss = (float) $this->get('max_daily_loss_pct', 1.0);
        $dailyPnl = $this->dailyRealizedPnl();
        if ($equity > 0 && (-$dailyPnl / $equity * 100) >= $maxDailyLoss) $reasons[] = "Daily loss limit reached ({$maxDailyLoss}%)";

        $maxConsec = (int) $this->get('max_consecutive_losses', 3);
        if ($this->consecutiveLosses() >= $maxConsec) $reasons[] = "Consecutive loss limit reached ({$maxConsec})";

        // Correlated exposure: same base/quote currency.
        if ($ts) {
            $maxCorr = (int) $this->get('max_correlated_exposure', 1);
            $corr = PaperPosition::where('status', 'OPEN')->get()->filter(function ($p) use ($ts) {
                $s = TradingSymbol::where('symbol', $p->symbol)->first();
                if (! $s) return false;
                return $s->base_currency === $ts->base_currency || $s->quote_currency === $ts->quote_currency;
            })->count();
            if ($corr >= $maxCorr) $reasons[] = 'Correlated currency exposure limit reached';
        }

        // Duplicate proposal guard.
        $dup = \App\Models\TradeProposal::where('symbol', $symbol)
            ->where('direction', $direction)->where('status', 'APPROVED')
            ->where('created_at', '>=', now()->subMinutes(30))->exists();
        if ($dup) $reasons[] = 'Duplicate approved proposal within 30 minutes';

        $volume = 0; $riskAmount = 0;
        if ($reasons === [] && $account && $ts && $slDist > 0) {
            $riskPct = (float) $this->get('risk_per_trade_pct', 0.25);
            $riskAmount = round($equity * $riskPct / 100, 2);
            $volume = $this->volumeForRisk($ts, $slDist, $riskAmount);
            $maxVol = (float) $this->get('max_volume_lots', 1.0);
            if ($volume > $maxVol) { $volume = $maxVol; $riskAmount = $this->riskForVolume($ts, $slDist, $volume); }
            if ($volume < 0.01) $reasons[] = 'Computed volume below minimum 0.01 lots';
        }

        return ['approved' => $reasons === [], 'reasons' => $reasons, 'volume' => round($volume, 2), 'risk_amount' => round($riskAmount, 2)];
    }

    public function pipSize(string $symbol): float
    {
        return str_ends_with($symbol, 'JPY') ? 0.01 : 0.0001;
    }

    /** Lots such that sl distance * tick value ~= risk amount. */
    public function volumeForRisk(TradingSymbol $ts, float $slDistance, float $riskAmount): float
    {
        $tickSize = (float) $ts->tick_size ?: 0.00001;
        $tickValue = (float) $ts->tick_value ?: 1.0;
        if ($tickSize <= 0 || $slDistance <= 0) return 0;
        $ticks = $slDistance / $tickSize;
        $lossPerLot = $ticks * $tickValue;
        if ($lossPerLot <= 0) return 0;
        $lots = $riskAmount / $lossPerLot;
        return floor($lots * 100) / 100;
    }

    public function riskForVolume(TradingSymbol $ts, float $slDistance, float $volume): float
    {
        $tickSize = (float) $ts->tick_size ?: 0.00001;
        $tickValue = (float) $ts->tick_value ?: 1.0;
        return round(($slDistance / $tickSize) * $tickValue * $volume, 2);
    }

    public function dailyRealizedPnl(): float
    {
        return (float) \App\Models\PaperPosition::where('status', 'CLOSED')
            ->whereDate('closed_at', today())
            ->sum('realized_pnl');
    }

    public function consecutiveLosses(): int
    {
        $positions = \App\Models\PaperPosition::where('status', 'CLOSED')
            ->orderBy('closed_at', 'desc')->limit(20)->pluck('realized_pnl');
        $n = 0;
        foreach ($positions as $pnl) {
            if ((float) $pnl < 0) $n++; else break;
        }
        return $n;
    }
}
