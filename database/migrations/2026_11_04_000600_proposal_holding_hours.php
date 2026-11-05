<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trade_proposals', function (Blueprint $table) {
            $table->integer('expected_holding_hours')->nullable()->after('confidence');
        });
    }

    public function down(): void
    {
        Schema::table('trade_proposals', function (Blueprint $table) {
            $table->dropColumn('expected_holding_hours');
        });
    }
};
