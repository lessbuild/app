<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Automatic scaling: replicas follow the servers' CPU between the minimum and maximum. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('environments', function (Blueprint $table): void {
            $table->boolean('autoscale_enabled')->default(false);
            $table->unsignedTinyInteger('autoscale_cpu_target')->default(70);
            $table->timestamp('autoscaled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('environments', fn (Blueprint $table) => $table->dropColumn(['autoscale_enabled', 'autoscale_cpu_target', 'autoscaled_at']));
    }
};
