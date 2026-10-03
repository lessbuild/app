<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add latency and conversion limits to the watch after each deploy, and the before/after report to builds.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('environments', function (Blueprint $table): void {
            $table->unsignedSmallInteger('rollback_latency_percent')->nullable();
            $table->unsignedSmallInteger('rollback_conversion_drop_percent')->nullable();
        });
        Schema::table('builds', function (Blueprint $table): void {
            $table->json('observation_report')->nullable();
        });
    }

    /**
     * Remove them.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('environments', function (Blueprint $table): void {
            $table->dropColumn(['rollback_latency_percent', 'rollback_conversion_drop_percent']);
        });
        Schema::table('builds', function (Blueprint $table): void {
            $table->dropColumn('observation_report');
        });
    }
};
