<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backtest_trades', function (Blueprint $table) {
            $table->decimal('stop_loss', 18, 5)->nullable()->after('exit');
            $table->decimal('take_profit', 18, 5)->nullable()->after('stop_loss');
            $table->decimal('volume', 18, 2)->nullable()->after('take_profit');
            $table->decimal('costs', 18, 2)->nullable()->after('volume');
            $table->string('close_reason', 32)->nullable()->after('costs');
            $table->text('rationale')->nullable()->after('close_reason');
        });
    }

    public function down(): void
    {
        Schema::table('backtest_trades', function (Blueprint $table) {
            $table->dropColumn(['stop_loss', 'take_profit', 'volume', 'costs', 'close_reason', 'rationale']);
        });
    }
};
