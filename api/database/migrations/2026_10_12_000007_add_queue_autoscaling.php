<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let autoscaling follow queue length: how many waiting jobs each replica should have at most.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('environments', function (Blueprint $table): void {
            $table->unsignedInteger('autoscale_queue_jobs')->nullable();
        });
    }

    /**
     * Remove it.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('environments', function (Blueprint $table): void {
            $table->dropColumn('autoscale_queue_jobs');
        });
    }
};
