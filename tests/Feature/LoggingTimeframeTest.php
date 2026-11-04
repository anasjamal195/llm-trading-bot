<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\LLM\OpenRouterService;
use App\Services\MarketData\MarketDataService;
use App\Services\System\AppLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoggingTimeframeTest extends TestCase
{
    use RefreshDatabase;

    public function test_regime_timeframe_map(): void
    {
        $this->assertSame('H4', MarketDataService::regimeTimeframe('TRENDING'));
        $this->assertSame('M15', MarketDataService::regimeTimeframe('RANGING'));
        $this->assertSame('H1', MarketDataService::regimeTimeframe('VOLATILE'));
        $this->assertSame('H1', MarketDataService::regimeTimeframe('UNKNOWN'));
        $this->assertSame('H1', MarketDataService::regimeTimeframe('BOGUS'));
        Setting::put('timeframe.regime_map', '{"TRENDING":"D1"}', 'timeframe');
        $this->assertSame('D1', MarketDataService::regimeTimeframe('TRENDING'));
        $this->assertSame('H1', MarketDataService::regimeTimeframe('RANGING')); // missing key falls back
        Setting::put('timeframe.regime_map', '{"TRENDING":"XYZ"}', 'timeframe');
        $this->assertSame('H1', MarketDataService::regimeTimeframe('TRENDING')); // invalid TF falls back
    }

    public function test_analysis_timeframe_defaults_and_validation(): void
    {
        \App\Models\TradingSymbol::create(['symbol' => 'EURUSD', 'base_currency' => 'EUR', 'quote_currency' => 'USD']);
        $this->assertSame('H1', app(MarketDataService::class)->analysisTimeframe('EURUSD'));
        $this->assertSame('H1', app(MarketDataService::class)->analysisTimeframe('MISSING'));
        \App\Models\TradingSymbol::where('symbol', 'EURUSD')->update(['analysis_timeframe' => 'M99']);
        $this->assertSame('H1', app(MarketDataService::class)->analysisTimeframe('EURUSD'));
    }

    public function test_logger_redacts_secrets(): void
    {
        $log = app(AppLogger::class);
        $out = $log->redact(['api_key' => 'sk-secret', 'symbol' => 'EURUSD', 'nested' => ['token' => 'abc'], 'auth' => 'Bearer xyz']);
        $this->assertSame('[redacted]', $out['api_key']);
        $this->assertSame('EURUSD', $out['symbol']);
        $this->assertSame('[redacted]', $out['nested']['token']);
        $this->assertSame('[redacted]', $out['auth']);
        $log->info('test', 'hello', ['api_key' => 'sk-secret']);
        $row = \App\Models\SystemLog::where('component', 'test')->first();
        $this->assertNotNull($row);
        $this->assertSame('[redacted]', $row->context['api_key']);
    }

    public function test_pricing_lookup_with_fallback(): void
    {
        $svc = app(OpenRouterService::class);
        Setting::put('openrouter.pricing', '{"a/b":{"in":0.5,"out":1.5}}', 'openrouter');
        $this->assertSame(['in' => 0.5, 'out' => 1.5], $svc->priceFor('a/b'));
        $this->assertSame(['in' => 1.00, 'out' => 2.00], $svc->priceFor('unknown/model'));
    }
}
