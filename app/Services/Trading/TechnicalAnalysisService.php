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
}
