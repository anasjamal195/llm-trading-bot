<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backtest_trades', function (Blueprint $table) {
            $table->decimal('pnl_pct', 10, 4)->nullable()->after('pnl');
        });
    }

    public function down(): void
    {
        Schema::table('backtest_trades', function (Blueprint $table) {
            $table->dropColumn('pnl_pct');
        });
    }
};
