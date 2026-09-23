<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('monitor')->create('service_level_objectives', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('indicator', 24);
            $table->string('service', 100)->nullable();
            $table->string('route', 255)->nullable();
            $table->decimal('target', 5, 3);
            $table->unsignedTinyInteger('window_days')->default(30);
            $table->double('latency_threshold_ms')->nullable();
            $table->unsignedSmallInteger('status_min')->default(200);
            $table->unsignedSmallInteger('status_max')->default(399);
            $table->boolean('enabled')->default(true);
            $table->timestamps(6);
            $table->softDeletes('deleted_at', 6);
            $table->index(['environment_id', 'enabled', 'id'], 'slo_environment_index');
            $table->index(['indicator', 'enabled', 'id'], 'slo_indicator_index');
        });
    }

    public function down(): void
    {
        if (DB::connection('monitor')->table('service_level_objectives')->exists()) {
            throw new RuntimeException('SLO history exists. Use a forward migration instead of deleting objectives.');
        }

        Schema::connection('monitor')->dropIfExists('service_level_objectives');
    }
};
