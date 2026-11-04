<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('task_type');
            $table->string('agent_name');
            $table->string('symbol')->nullable();
            $table->string('currency', 8)->nullable();
            $table->integer('priority')->default(5);
            $table->string('status')->default('PENDING');
            $table->json('input_context')->nullable();
            $table->json('output_result')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->integer('retry_count')->default(0);
            $table->text('error_message')->nullable();
            $table->string('lease_owner')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->string('dedupe_key')->nullable();
            $table->timestamps();
            $table->index(['status', 'scheduled_at']);
            $table->index(['agent_name', 'status']);
            $table->index('dedupe_key');
            $table->index(['symbol', 'status']);
        });

        Schema::create('model_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agent_task_id')->nullable();
            $table->string('agent_name');
            $table->string('model');
            $table->string('provider')->default('openrouter');
            $table->integer('input_tokens')->nullable();
            $table->integer('output_tokens')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->decimal('estimated_cost', 12, 6)->nullable();
            $table->string('status')->default('OK');
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['agent_name', 'created_at']);
        });

        Schema::create('prompt_versions', function (Blueprint $table) {
            $table->id();
            $table->string('agent_name');
            $table->integer('version')->default(1);
            $table->text('system_prompt');
            $table->text('user_template')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['agent_name', 'version']);
        });

        Schema::create('trading_strategies', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('parameters')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->integer('version')->default(1);
            $table->timestamps();
        });

        Schema::create('strategy_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trading_strategy_id')->constrained('trading_strategies')->cascadeOnDelete();
            $table->integer('version');
            $table->integer('total_trades')->default(0);
            $table->decimal('win_rate', 6, 2)->nullable();
            $table->decimal('profit_factor', 10, 4)->nullable();
            $table->decimal('expectancy', 18, 2)->nullable();
            $table->decimal('net_profit', 18, 2)->nullable();
            $table->decimal('max_drawdown', 18, 2)->nullable();
            $table->string('verdict')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();
        });

        Schema::create('trade_proposals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agent_task_id')->nullable();
            $table->unsignedBigInteger('market_snapshot_id')->nullable();
            $table->string('symbol');
            $table->string('direction');
            $table->string('order_type');
            $table->decimal('entry', 18, 5);
            $table->decimal('stop_loss', 18, 5);
            $table->decimal('take_profit', 18, 5);
            $table->string('strategy_code')->nullable();
            $table->decimal('confidence', 5, 2)->nullable();
            $table->text('rationale')->nullable();
            $table->text('invalidation')->nullable();
            $table->decimal('risk_reward', 8, 3)->nullable();
            $table->string('status')->default('PROPOSED');
            $table->text('validation_result')->nullable();
            $table->string('model')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['symbol', 'status']);
        });

        Schema::create('paper_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trading_account_id')->constrained('trading_accounts')->cascadeOnDelete();
            $table->unsignedBigInteger('trade_proposal_id')->nullable();
            $table->string('symbol');
            $table->string('order_type');
            $table->string('direction');
            $table->decimal('entry_price', 18, 5);
            $table->decimal('stop_loss', 18, 5);
            $table->decimal('take_profit', 18, 5);
            $table->decimal('volume', 12, 2);
            $table->decimal('risk_amount', 18, 2)->default(0);
            $table->string('status')->default('PENDING');
            $table->decimal('fill_price', 18, 5)->nullable();
            $table->timestamp('filled_at')->nullable();
            $table->timestamp('expires_at');
            $table->text('reject_reason')->nullable();
            $table->boolean('ambiguous_fill')->default(false);
            $table->string('created_by')->default('ai');
            $table->timestamps();
            $table->index(['symbol', 'status']);
            $table->index('status');
        });

        Schema::create('paper_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trading_account_id')->constrained('trading_accounts')->cascadeOnDelete();
            $table->foreignId('paper_order_id')->constrained('paper_orders')->cascadeOnDelete();
            $table->string('symbol');
            $table->string('direction');
            $table->decimal('entry_price', 18, 5);
            $table->decimal('volume', 12, 2);
            $table->decimal('stop_loss', 18, 5);
            $table->decimal('take_profit', 18, 5);
            $table->decimal('current_price', 18, 5)->nullable();
            $table->decimal('unrealized_pnl', 18, 2)->default(0);
            $table->decimal('realized_pnl', 18, 2)->nullable();
            $table->decimal('costs', 18, 2)->default(0);
            $table->string('status')->default('OPEN');
            $table->string('close_reason')->nullable();
            $table->decimal('exit_price', 18, 5)->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('max_hold_until');
            $table->timestamp('next_review_at')->nullable();
            $table->text('thesis')->nullable();
            $table->string('strategy_code')->nullable();
            $table->boolean('ambiguous_exit')->default(false);
            $table->timestamps();
            $table->index(['symbol', 'status']);
            $table->index('status');
        });

        Schema::create('trade_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paper_position_id')->constrained('paper_positions')->cascadeOnDelete();
            $table->text('prediction_summary')->nullable();
            $table->text('outcome_summary')->nullable();
            $table->string('entry_quality')->nullable();
            $table->string('exit_quality')->nullable();
            $table->text('lessons')->nullable();
            $table->json('metrics')->nullable();
            $table->string('model')->nullable();
            $table->timestamps();
        });

        Schema::create('performance_metrics', function (Blueprint $table) {
            $table->id();
            $table->date('period_date');
            $table->string('scope')->default('global');
            $table->string('scope_key')->nullable();
            $table->integer('total_trades')->default(0);
            $table->integer('wins')->default(0);
            $table->integer('losses')->default(0);
            $table->decimal('win_rate', 6, 2)->nullable();
            $table->decimal('gross_profit', 18, 2)->default(0);
            $table->decimal('gross_loss', 18, 2)->default(0);
            $table->decimal('net_profit', 18, 2)->default(0);
            $table->decimal('profit_factor', 10, 4)->nullable();
            $table->decimal('expectancy', 18, 2)->nullable();
            $table->decimal('max_drawdown', 18, 2)->nullable();
            $table->timestamps();
            $table->unique(['period_date', 'scope', 'scope_key']);
        });

        Schema::create('improvement_proposals', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('hypothesis')->nullable();
            $table->json('candidate_config')->nullable();
            $table->string('status')->default('DRAFT');
            $table->json('evaluation')->nullable();
            $table->timestamps();
        });

        Schema::create('backtest_runs', function (Blueprint $table) {
            $table->id();
            $table->string('strategy_code');
            $table->string('symbol');
            $table->string('timeframe', 8)->default('H1');
            $table->timestamp('from')->nullable();
            $table->timestamp('to')->nullable();
            $table->json('parameters')->nullable();
            $table->string('status')->default('PENDING');
            $table->json('results')->nullable();
            $table->timestamps();
        });

        Schema::create('backtest_trades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backtest_run_id')->constrained('backtest_runs')->cascadeOnDelete();
            $table->string('direction');
            $table->decimal('entry', 18, 5);
            $table->decimal('exit', 18, 5);
            $table->decimal('pnl', 18, 2);
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backtest_trades');
        Schema::dropIfExists('backtest_runs');
        Schema::dropIfExists('improvement_proposals');
        Schema::dropIfExists('performance_metrics');
        Schema::dropIfExists('trade_analyses');
        Schema::dropIfExists('paper_positions');
        Schema::dropIfExists('paper_orders');
        Schema::dropIfExists('trade_proposals');
        Schema::dropIfExists('strategy_evaluations');
        Schema::dropIfExists('trading_strategies');
        Schema::dropIfExists('prompt_versions');
        Schema::dropIfExists('model_usage_logs');
        Schema::dropIfExists('agent_tasks');
    }
};
