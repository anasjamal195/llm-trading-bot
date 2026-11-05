<?php

namespace App\Services\MarketData;

use App\Services\MT5\CandleProvider;

/**
 * Live tick feed (bid/ask) from the MT5 bridge. Used ONLY for monitoring
 * open positions and pending orders at ~1s granularity — never for analysis
 * candles, which always come from completed OHLC bars.
 */
class TickService
{
    public function __construct(private CandleProvider $provider) {}

    /** @return array{bid:float,ask:float,time:int} */
    public function tick(string $symbol): array
    {
        $t = $this->provider->tick($symbol);
        $bid = (float) ($t['bid'] ?? 0);
        $ask = (float) ($t['ask'] ?? 0);
        if ($bid <= 0 || $ask <= 0 || $ask < $bid) {
            throw new \RuntimeException("Invalid tick for {$symbol}");
        }
        return ['bid' => $bid, 'ask' => $ask, 'time' => (int) ($t['time'] ?? time())];
    }
}
