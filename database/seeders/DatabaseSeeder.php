<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use App\Models\Setting;
use App\Models\TradingAccount;
use App\Models\TradingStrategy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        AdminUser::firstOrCreate(['email' => 'admin@example.com'], [
            'name' => 'Administrator', 'password' => Hash::make('ChangeMe123!'), 'is_enabled' => true,
        ]);

        TradingAccount::firstOrCreate(['id' => 1], [
            'name' => 'Primary Paper Account', 'currency' => 'USD',
            'initial_balance' => 10000, 'balance' => 10000, 'equity' => 10000,
        ]);

        $pairs = [
            'EURUSD' => ['EUR', 'USD'], 'GBPUSD' => ['GBP', 'USD'], 'USDJPY' => ['USD', 'JPY'],
            'USDCHF' => ['USD', 'CHF'], 'AUDUSD' => ['AUD', 'USD'], 'USDCAD' => ['USD', 'CAD'],
            'NZDUSD' => ['NZD', 'USD'],
        ];
        foreach ($pairs as $sym => [$base, $quote]) {
            \App\Models\TradingSymbol::firstOrCreate(['symbol' => $sym], [
                'base_currency' => $base, 'quote_currency' => $quote,
                'is_enabled' => true, 'is_supported' => true,
                'spread_pips' => str_ends_with($sym, 'JPY') ? 1.8 : 1.2,
                'tick_size' => str_ends_with($sym, 'JPY') ? 0.001 : 0.00001,
            ]);
        }

        $strategies = [
            ['TREND_PULLBACK', 'Trend-following pullback', 'Enter pullbacks in established trends'],
            ['MOMENTUM_BREAKOUT', 'Momentum breakout', 'Breakout of consolidation with momentum'],
            ['RANGE_REVERSAL', 'Range reversal', 'Fade range extremes back to mean'],
            ['SR_REJECTION', 'Support/resistance rejection', 'Rejection wicks at key levels'],
            ['VOLATILITY_EXPANSION', 'Volatility expansion', 'Expansion after squeeze'],
        ];
        foreach ($strategies as [$code, $name, $desc]) {
            TradingStrategy::firstOrCreate(['code' => $code], ['name' => $name, 'description' => $desc, 'is_enabled' => true]);
        }

        $defaults = [
            'market.timeframes' => 'M1,M15,H1,H4,D1',
            'market.max_stale_seconds' => '600',
            'risk.starting_balance' => '10000',
            'risk.risk_per_trade_pct' => '0.25',
            'risk.max_open_positions' => '2',
            'risk.max_pending_orders' => '4',
            'risk.max_daily_trades' => '10',
            'risk.max_daily_loss_pct' => '1.0',
            'risk.max_consecutive_losses' => '3',
            'risk.min_risk_reward' => '1.5',
            'risk.max_spread_pips' => '5',
            'risk.max_holding_hours' => '48',
            'risk.max_pending_order_hours' => '24',
            'risk.max_correlated_exposure' => '1',
            'risk.max_volume_lots' => '1.0',
            'risk.kill_switch' => '',
            'agents.min_interval_minutes' => '2',
            'agents.pair_cooldown_minutes' => '15',
            'openrouter.model.default' => 'meta-llama/llama-3.3-70b-instruct',
            'openrouter.temperature' => '0.2',
            'openrouter.max_tokens' => '1500',
            'openrouter.timeout' => '60',
            'openrouter.retries' => '2',
            'openrouter.daily_budget' => '500',
            'openrouter.base_url' => 'https://openrouter.ai/api/v1',
            'news.provider' => 'manual',
        ];
        foreach ($defaults as $k => $v) {
            Setting::firstOrCreate(['key' => $k], ['value' => $v, 'group' => explode('.', $k)[0]]);
        }

        $prompts = [
            'market_discovery' => 'You are a Forex market regime analyst. Given market snapshots for currency pairs, rank suitability for trading. You may reject ALL pairs. Respond ONLY with JSON: {"rankings":[{"symbol":"EURUSD","score":0-100,"suitable":bool,"regime":"TRENDING|RANGING|VOLATILE|UNKNOWN","reasons":"..."}],"session":"...","notes":"...","next_review_minutes":5-60}.',
        ];
        foreach ($prompts as $agent => $text) {
            \App\Models\PromptVersion::firstOrCreate(['agent_name' => $agent, 'version' => 1], ['system_prompt' => $text, 'is_active' => true]);
        }
    }
}
