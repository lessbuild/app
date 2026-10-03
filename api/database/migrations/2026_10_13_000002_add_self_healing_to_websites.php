<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let websites heal themselves: restart what's stopped when their health check fails.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->boolean('self_healing')->default(false);
        });
    }

    /**
     * Remove it.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->dropColumn('self_healing');
        });
    }
};
