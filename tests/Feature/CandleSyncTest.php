<?php

namespace Tests\Feature;

use App\Models\Candle;
use App\Models\TradingSymbol;
use App\Services\MarketData\MarketDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CandleSyncTest extends TestCase
{
    use RefreshDatabase;

    private function bridgeCandles(string $from, int $n): array
    {
        $t = strtotime($from);
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $ts = $t + $i * 60;
            $out[] = ['time' => $ts, 'open' => 1.1, 'high' => 1.1005, 'low' => 1.0995, 'close' => 1.1002, 'tick_volume' => 10, 'spread' => 12, 'real_volume' => 0, 'time_utc' => gmdate('Y-m-d\TH:i:s\Z', $ts), 'binance_timestamp' => $ts * 1000];
        }
        return $out;
    }

    public function test_sync_anchors_on_count_not_start_time(): void
    {
        TradingSymbol::create(['symbol' => 'EURUSD', 'base_currency' => 'EUR', 'quote_currency' => 'USD', 'is_enabled' => true]);
        Http::fake(['*' => Http::response(['symbol' => 'EURUSD', 'interval' => '1m', 'count' => 3, 'candles' => $this->bridgeCandles('2026-01-05 00:00:00', 3)])]);

        $r1 = app(MarketDataService::class)->sync('EURUSD', 'M1');
        // Fixture bars are historical, so the forming-bar heuristic keeps all.
        $this->assertSame(3, $r1['inserted']);

        Http::assertSent(function ($req) {
            // Regression guard: the bridge treats startTime as END of window in
            // server time, so offset-based sync never advances. We must not send it.
            return ! isset($req->data()['startTime']);
        });

        // Second sync of the same bars inserts nothing (idempotent upsert).
        $r2 = app(MarketDataService::class)->sync('EURUSD', 'M1');
        $this->assertSame(0, $r2['inserted']);
        $this->assertSame(3, Candle::where('symbol', 'EURUSD')->count());
    }
}
