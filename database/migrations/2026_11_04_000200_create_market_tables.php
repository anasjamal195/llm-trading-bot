<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trading_symbols', function (Blueprint $table) {
            $table->id();
            $table->string('symbol')->unique();
            $table->string('base_currency', 8);
            $table->string('quote_currency', 8);
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_supported')->default(true);
            $table->decimal('spread_pips', 10, 2)->default(1.5);
            $table->decimal('contract_size', 18, 2)->default(100000);
            $table->decimal('tick_size', 18, 8)->default(0.00001);
            $table->decimal('tick_value', 18, 4)->default(1.0000);
            $table->decimal('commission_per_lot', 18, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('trading_candles', function (Blueprint $table) {
            $table->id();
            $table->string('symbol');
            $table->string('timeframe', 8);
            $table->timestamp('candle_time');
            $table->decimal('open', 18, 5);
            $table->decimal('high', 18, 5);
            $table->decimal('low', 18, 5);
            $table->decimal('close', 18, 5);
            $table->unsignedBigInteger('tick_volume')->default(0);
            $table->integer('spread')->nullable();
            $table->decimal('real_volume', 18, 2)->nullable();
            $table->string('source')->default('mt5');
            $table->timestamps();
            $table->unique(['symbol', 'timeframe', 'candle_time']);
            $table->index(['symbol', 'timeframe', 'candle_time']);
        });

        Schema::create('candle_sync_states', function (Blueprint $table) {
            $table->id();
            $table->string('symbol');
            $table->string('timeframe', 8);
            $table->timestamp('last_candle_time')->nullable();
            $table->string('status')->default('OK');
            $table->text('last_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['symbol', 'timeframe']);
        });

        Schema::create('market_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('symbol');
            $table->decimal('price', 18, 5);
            $table->string('timeframe', 8)->default('M1');
            $table->timestamp('candle_time')->nullable();
            $table->decimal('atr', 18, 5)->nullable();
            $table->decimal('volatility_pct', 10, 4)->nullable();
            $table->string('trend')->nullable();
            $table->string('regime')->nullable();
            $table->json('indicators')->nullable();
            $table->timestamps();
            $table->index(['symbol', 'created_at']);
        });

        Schema::create('news_articles', function (Blueprint $table) {
            $table->id();
            $table->string('headline');
            $table->string('source')->nullable();
            $table->string('url', 1024)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('retrieved_at')->nullable();
            $table->string('currency', 8)->nullable();
            $table->string('event_category')->nullable();
            $table->string('impact')->nullable();
            $table->string('sentiment')->nullable();
            $table->decimal('confidence', 5, 2)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('summary')->nullable();
            $table->string('hash', 64)->nullable()->unique();
            $table->timestamps();
            $table->index(['currency', 'published_at']);
        });

        Schema::create('economic_events', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('currency', 8);
            $table->string('impact')->nullable();
            $table->timestamp('event_time');
            $table->string('forecast')->nullable();
            $table->string('previous')->nullable();
            $table->string('actual')->nullable();
            $table->timestamps();
            $table->index(['currency', 'event_time']);
        });

        Schema::create('currency_research', function (Blueprint $table) {
            $table->id();
            $table->string('currency', 8);
            $table->string('outlook');
            $table->decimal('confidence', 5, 2)->nullable();
            $table->text('fundamental_outlook')->nullable();
            $table->string('news_sentiment')->nullable();
            $table->text('evidence')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('model')->nullable();
            $table->timestamps();
            $table->index(['currency', 'created_at']);
        });

        Schema::create('pair_rankings', function (Blueprint $table) {
            $table->id();
            $table->string('symbol');
            $table->decimal('score', 6, 2)->nullable();
            $table->boolean('suitable')->default(false);
            $table->string('regime')->nullable();
            $table->text('reasons')->nullable();
            $table->timestamps();
            $table->index(['symbol', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pair_rankings');
        Schema::dropIfExists('currency_research');
        Schema::dropIfExists('economic_events');
        Schema::dropIfExists('news_articles');
        Schema::dropIfExists('market_snapshots');
        Schema::dropIfExists('candle_sync_states');
        Schema::dropIfExists('trading_candles');
        Schema::dropIfExists('trading_symbols');
    }
};
