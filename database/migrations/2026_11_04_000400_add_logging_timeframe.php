<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_logs', function (Blueprint $table) {
            $table->id();
            $table->string('level', 10)->default('info');
            $table->string('component', 64);
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamps();
            $table->index(['component', 'created_at']);
            $table->index(['level', 'created_at']);
            $table->index('created_at');
        });

        Schema::table('trading_symbols', function (Blueprint $table) {
            $table->string('analysis_timeframe', 8)->default('H1')->after('is_supported');
        });
    }

    public function down(): void
    {
        Schema::table('trading_symbols', function (Blueprint $table) {
            $table->dropColumn('analysis_timeframe');
        });
        Schema::dropIfExists('system_logs');
    }
};
