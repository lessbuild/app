<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('environments', function (Blueprint $table): void {
            $table->string('deployment_strategy')->default('blue_green');
            $table->unsignedTinyInteger('rolling_pause_seconds')->default(2);
        });
    }

    public function down(): void
    {
        Schema::table('environments', function (Blueprint $table): void {
            $table->dropColumn(['deployment_strategy', 'rolling_pause_seconds']);
        });
    }
};
