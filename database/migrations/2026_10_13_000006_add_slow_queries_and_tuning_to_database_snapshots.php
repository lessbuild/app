<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keep each database inspection's slow queries and the server status that tuning suggestions come from.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('database_snapshots', function (Blueprint $table): void {
            $table->boolean('slow_log_enabled')->nullable();
            $table->json('slow_queries')->nullable();
            $table->json('server_status')->nullable();
        });
    }

    /**
     * Remove them.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('database_snapshots', function (Blueprint $table): void {
            $table->dropColumn(['slow_log_enabled', 'slow_queries', 'server_status']);
        });
    }
};
