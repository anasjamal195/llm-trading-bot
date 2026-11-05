<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trade_proposals', function (Blueprint $table) {
            $table->decimal('probability', 5, 2)->nullable()->after('confidence');
        });
    }

    public function down(): void
    {
        Schema::table('trade_proposals', function (Blueprint $table) {
            $table->dropColumn('probability');
        });
    }
};
