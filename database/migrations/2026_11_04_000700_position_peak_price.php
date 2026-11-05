<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paper_positions', function (Blueprint $table) {
            $table->decimal('peak_price', 18, 5)->nullable()->after('current_price');
        });
    }

    public function down(): void
    {
        Schema::table('paper_positions', function (Blueprint $table) {
            $table->dropColumn('peak_price');
        });
    }
};
