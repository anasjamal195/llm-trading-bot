<?php

namespace App\Services\Trading;

class TechnicalAnalysisService
{
    public function sma(array $values, int $period): ?float
    {
        if (count($values) < $period) return null;
        $slice = array_slice($values, -$period);
        return array_sum($slice) / $period;
    }

    public function ema(array $values, int $period): ?float
    {
        if (count($values) < $period) return null;
        $k = 2 / ($period + 1);
        $ema = array_sum(array_slice($values, 0, $period)) / $period;
        foreach (array_slice($values, $period) as $v) {
            $ema = ($v * $k) + ($ema * (1 - $k));
        }
        return $ema;
    }

    public function rsi(array $closes, int $period = 14): ?float
    {
        if (count($closes) < $period + 1) return null;
        $gains = 0; $losses = 0;
        $slice = array_slice($closes, -($period + 1));
        for ($i = 1; $i < count($slice); $i++) {
            $d = $slice[$i] - $slice[$i - 1];
            if ($d >= 0) $gains += $d; else $losses -= $d;
        }
        if ($losses == 0) return 100.0;
        $rs = ($gains / $period) / ($losses / $period);
        return round(100 - (100 / (1 + $rs)), 2);
    }

    public function atr(array $highs, array $lows, array $closes, int $period = 14): ?float
    {
        $n = count($closes);
        if ($n < $period + 1) return null;
        $trs = [];
        for ($i = max(1, $n - $period); $i < $n; $i++) {
            $trs[] = max(
                $highs[$i] - $lows[$i],
                abs($highs[$i] - $closes[$i - 1]),
                abs($lows[$i] - $closes[$i - 1])
            );
        }
        return array_sum($trs) / count($trs);
    }

    public function highest(array $values, int $period): ?float
    {
        if (count($values) < $period) return null;
        return max(array_slice($values, -$period));
    }

    public function lowest(array $values, int $period): ?float
    {
        if (count($values) < $period) return null;
        return min(array_slice($values, -$period));
    }

    public function trend(array $closes): string
    {
        $sma20 = $this->sma($closes, 20);
        $sma50 = $this->sma($closes, 50);
        $last = end($closes);
        if ($sma20 === null || $sma50 === null) return 'UNKNOWN';
        if ($last > $sma20 && $sma20 > $sma50) return 'UP';
        if ($last < $sma20 && $sma20 < $sma50) return 'DOWN';
        return 'RANGE';
    }

    public function regime(array $closes, float $atr): string
    {
        $n = count($closes);
        if ($n < 30 || $atr <= 0) return 'UNKNOWN';
        $last = $closes[$n - 1];
        $range = max(array_slice($closes, -30)) - min(array_slice($closes, -30));
        $ratio = $range / $atr;
        if ($ratio > 6) return 'TRENDING';
        if ($ratio < 2.5) return 'RANGING';
        return 'VOLATILE';
    }

    /** Wilder ADX(14) trend-strength 0-100. Needs highs/lows/closes of the window. Null when too short. */
    public function adx(array $highs, array $lows, array $closes, int $period = 14): ?float
    {
        $n = count($closes);
        if ($n < $period * 2 + 1) return null;
        $plusDM = $minusDM = $tr = [];
        for ($i = 1; $i < $n; $i++) {
            $up = $highs[$i] - $highs[$i - 1];
            $dn = $lows[$i - 1] - $lows[$i];
            $plusDM[] = ($up > $dn && $up > 0) ? $up : 0.0;
            $minusDM[] = ($dn > $up && $dn > 0) ? $dn : 0.0;
            $tr[] = max($highs[$i] - $lows[$i], abs($highs[$i] - $closes[$i - 1]), abs($lows[$i] - $closes[$i - 1]));
        }
        // Wilder smoothing seeded on first $period values.
        $sPlus = array_sum(array_slice($plusDM, 0, $period));
        $sMinus = array_sum(array_slice($minusDM, 0, $period));
        $sTR = array_sum(array_slice($tr, 0, $period));
        $dxs = [];
        $m = count($plusDM);
        for ($i = $period; $i < $m; $i++) {
            $sPlus = $sPlus - $sPlus / $period + $plusDM[$i];
            $sMinus = $sMinus - $sMinus / $period + $minusDM[$i];
            $sTR = $sTR - $sTR / $period + $tr[$i];
            if ($sTR <= 0) continue;
            $pdi = 100 * $sPlus / $sTR;
            $mdi = 100 * $sMinus / $sTR;
            $dxs[] = ($pdi + $mdi) > 0 ? 100 * abs($pdi - $mdi) / ($pdi + $mdi) : 0.0;
        }
        if (count($dxs) < $period) return null;
        return round(array_sum(array_slice($dxs, -$period)) / $period, 2);
    }
}
