<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use App\Models\Setting;
use App\Models\TradingAccount;
use App\Models\TradingStrategy;
use App\Models\TradingSymbol;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Production admin login. Override with SEED_ADMIN_EMAIL / SEED_ADMIN_PASSWORD
        // environment variables when seeding. Change the password after first login.
        $adminEmail = env('SEED_ADMIN_EMAIL', 'admin@tradingbot.graphichat.ai');
        $adminPass = env('SEED_ADMIN_PASSWORD', 'yXbeenwrPEj2q4FFQpwY');
        AdminUser::updateOrCreate(['email' => $adminEmail], [
            'name' => 'Administrator', 'password' => Hash::make($adminPass), 'is_enabled' => true,
        ]);
        // Remove the default placeholder login if it exists.
        AdminUser::where('email', 'admin@example.com')->where('email', '!=', $adminEmail)->delete();
        $this->command->info("Seeded admin: {$adminEmail}");

        TradingAccount::firstOrCreate(['id' => 1], [
            'name' => 'Primary Paper Account', 'currency' => 'USD',
            'initial_balance' => 10000, 'balance' => 10000, 'equity' => 10000,
        ]);

        $pairs = [
            'EURUSD' => ['EUR', 'USD'], 'GBPUSD' => ['GBP', 'USD'], 'USDJPY' => ['USD', 'JPY'],
            'USDCHF' => ['USD', 'CHF'], 'AUDUSD' => ['AUD', 'USD'], 'USDCAD' => ['USD', 'CAD'],
            'NZDUSD' => ['NZD', 'USD'],
            // Liquid minors for scalping rotation.
            'EURGBP' => ['EUR', 'GBP'], 'EURJPY' => ['EUR', 'JPY'], 'GBPJPY' => ['GBP', 'JPY'],
            'AUDJPY' => ['AUD', 'JPY'],
        ];
        foreach ($pairs as $sym => [$base, $quote]) {
            TradingSymbol::firstOrCreate(['symbol' => $sym], [
                'base_currency' => $base, 'quote_currency' => $quote,
                'is_enabled' => true, 'is_supported' => true,
                'spread_pips' => str_ends_with($sym, 'JPY') ? 1.8 : 1.2,
                'tick_size' => str_ends_with($sym, 'JPY') ? 0.001 : 0.00001,
            ]);
        }

        // Gold (metal): 2-decimal quotes, 0.1 pips, 100oz contract.
        // $1 move on 1 lot = $100 → tick_size 0.01 with tick_value 1.0.
        \App\Models\TradingSymbol::firstOrCreate(['symbol' => 'XAUUSD'], [
            'base_currency' => 'XAU', 'quote_currency' => 'USD',
            'is_enabled' => true, 'is_supported' => true,
            'pip_size' => 0.1, 'spread_pips' => 2.0,
            'contract_size' => 100, 'tick_size' => 0.01, 'tick_value' => 1.0,
        ]);
        // Silver: 3-decimal quotes; $1 move on 1 lot (5000oz) = $5000.
        \App\Models\TradingSymbol::firstOrCreate(['symbol' => 'XAGUSD'], [
            'base_currency' => 'XAG', 'quote_currency' => 'USD',
            'is_enabled' => true, 'is_supported' => true,
            'pip_size' => 0.01, 'spread_pips' => 3.0,
            'contract_size' => 5000, 'tick_size' => 0.001, 'tick_value' => 5.0,
        ]);

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
            'market.timeframes' => 'M1,M5,M15,M30,H1,H4,D1',
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
            'risk.max_pending_order_hours' => '24',
            'risk.max_holding_hours' => '8',
            'risk.max_correlated_exposure' => '1',
            'risk.max_volume_lots' => '1.0',
            'risk.kill_switch' => '',
            'agents.min_interval_minutes' => '2',
            'agents.pair_cooldown_minutes' => '5',
            'openrouter.model.default' => 'meta-llama/llama-3.3-70b-instruct',
            // DeepSeek v3.2 for analysis + decisions (cheap, 163k context, strong reasoning).
            'openrouter.model.market_discovery' => 'deepseek/deepseek-v3.2',
            'openrouter.model.market_analysis' => 'deepseek/deepseek-v3.2',
            'openrouter.model.strategy_selection' => 'deepseek/deepseek-v3.2',
            'openrouter.model.trade_decision' => 'deepseek/deepseek-v3.2',
            'openrouter.model.chief_trading' => 'deepseek/deepseek-v3.2',
            // Cheap search-enabled model for the news agent (live web lookup + citations).
            'openrouter.model.news_research' => 'perplexity/sonar',
            'openrouter.temperature' => '0.2',
            'openrouter.max_tokens' => '1500',
            'openrouter.max_tokens.chief_trading' => '2500',
            'openrouter.max_tokens.market_discovery' => '2000',
            'openrouter.timeout' => '60',
            'openrouter.retries' => '2',
            'openrouter.daily_budget' => '500',
            'openrouter.base_url' => 'https://openrouter.ai/api/v1',
            'timeframe.regime_map' => '{"TRENDING":"H4","RANGING":"M15","VOLATILE":"H1","UNKNOWN":"H1"}',
            'openrouter.pricing' => '{"deepseek/deepseek-chat":{"in":0.14,"out":0.28},"deepseek/deepseek-v3.2":{"in":0.26,"out":0.38},"meta-llama/llama-3.3-70b-instruct":{"in":0.35,"out":0.4},"openai/gpt-4o-mini":{"in":0.15,"out":0.6},"anthropic/claude-3.5-haiku":{"in":0.8,"out":4.0},"google/gemini-flash-1.5":{"in":0.075,"out":0.3},"perplexity/sonar":{"in":1.0,"out":1.0},"perplexity/sonar-pro":{"in":3.0,"out":15.0}}',
            'news.provider' => 'manual',
            'notifications.alert_email' => 'anasj5749@gmail.com,graphichat.ai@gmail.com',
        ];

        // OpenRouter gateway key (stored encrypted). NEVER hardcode the key here:
        // export SEED_OPENROUTER_KEY=sk-or-... before seeding on the server.
        $seedKey = env('SEED_OPENROUTER_KEY', '');
        if ($seedKey !== '') {
            Setting::put('openrouter.api_key', $seedKey, 'openrouter', true);
        } else {
            $this->command->warn('SEED_OPENROUTER_KEY not set — skipping LLM key seed. Add it in Settings later.');
        }
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
