<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trading_symbols', function (Blueprint $table) {
            $table->decimal('pip_size', 18, 8)->default(0.0001)->after('quote_currency');
        });
        // Metals (and any 2/3-decimal instruments) use 0.1-pip conventions.
        DB::table('trading_symbols')->whereIn('base_currency', ['XAU', 'XAG', 'XPT', 'XPD'])->update(['pip_size' => 0.1]);
        DB::table('trading_symbols')->where('quote_currency', 'JPY')->update(['pip_size' => 0.01]);
        DB::table('trading_symbols')->where('symbol', 'XAUUSD')->update([
            'tick_size' => 0.01, 'tick_value' => 1.0, 'contract_size' => 100, 'spread_pips' => 2.0,
        ]);
    }

    public function down(): void
    {
        Schema::table('trading_symbols', function (Blueprint $table) {
            $table->dropColumn('pip_size');
        });
    }
};
