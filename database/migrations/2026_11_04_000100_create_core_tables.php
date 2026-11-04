<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('is_enabled')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('group')->default('general');
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->boolean('is_encrypted')->default(false);
            $table->timestamps();
            $table->index('group');
        });

        Schema::create('bot_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('STOPPED');
            $table->string('lock_owner')->nullable();
            $table->timestamp('lock_expires_at')->nullable();
            $table->json('state_snapshot')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->text('status_message')->nullable();
            $table->timestamps();
        });

        Schema::create('trading_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('Primary Paper Account');
            $table->string('currency', 3)->default('USD');
            $table->decimal('initial_balance', 18, 2)->default(10000);
            $table->decimal('balance', 18, 2)->default(10000);
            $table->decimal('equity', 18, 2)->default(10000);
            $table->decimal('realized_pnl', 18, 2)->default(0);
            $table->decimal('total_costs', 18, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('paper_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trading_account_id')->constrained('trading_accounts')->cascadeOnDelete();
            $table->string('type');
            $table->decimal('amount', 18, 2);
            $table->decimal('balance_after', 18, 2);
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('notes')->nullable();
            $table->string('idempotency_key')->unique();
            $table->timestamps();
            $table->index(['reference_type', 'reference_id']);
            $table->index('type');
        });

        Schema::create('paper_account_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trading_account_id')->constrained('trading_accounts')->cascadeOnDelete();
            $table->decimal('balance', 18, 2);
            $table->decimal('equity', 18, 2);
            $table->decimal('unrealized_pnl', 18, 2)->default(0);
            $table->decimal('realized_pnl', 18, 2)->default(0);
            $table->integer('open_positions')->default(0);
            $table->integer('pending_orders')->default(0);
            $table->date('snapshot_date');
            $table->timestamps();
            $table->unique(['trading_account_id', 'snapshot_date']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('category');
            $table->string('action');
            $table->unsignedBigInteger('admin_user_id')->nullable();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();
            $table->index(['category', 'action']);
            $table->index('created_at');
        });

        Schema::create('system_health_logs', function (Blueprint $table) {
            $table->id();
            $table->string('component');
            $table->string('status');
            $table->text('message')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();
            $table->index(['component', 'created_at']);
        });

        Schema::create('notification_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('recipient');
            $table->string('subject');
            $table->text('body')->nullable();
            $table->string('status')->default('SENT');
            $table->string('dedupe_key')->nullable();
            $table->timestamps();
            $table->index('dedupe_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('notification_settings');
        Schema::dropIfExists('system_health_logs');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('paper_account_snapshots');
        Schema::dropIfExists('paper_transactions');
        Schema::dropIfExists('trading_accounts');
        Schema::dropIfExists('bot_sessions');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('admin_users');
    }
};
