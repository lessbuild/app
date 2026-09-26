<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('monitor')->create('metric_series', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->char('identity_hash', 64);
            $table->string('source', 24);
            $table->string('name');
            $table->string('resource_label');
            $table->string('unit');
            $table->string('kind', 32);
            $table->string('temporality', 16)->nullable();
            $table->boolean('monotonic')->default(false);
            $table->json('descriptor');
            $table->dateTime('first_received_at', 6);
            $table->dateTime('last_received_at', 6);
            $table->timestamps(6);
            $table->unique(['environment_id', 'identity_hash'], 'metric_series_identity_unique');
            $table->index(['environment_id', 'last_received_at', 'id'], 'metric_series_catalog_index');
        });
        Schema::connection('monitor')->create('metric_samples', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('metric_series_id')->constrained('metric_series')->cascadeOnDelete();
            $table->foreignId('telemetry_event_id')->constrained()->cascadeOnDelete();
            $table->char('time_key', 20);
            $table->char('start_time_key', 20)->nullable();
            $table->char('value_hash', 64);
            $table->string('value_text', 64)->nullable();
            $table->double('value')->nullable();
            $table->string('state', 24);
            $table->dateTime('occurred_at', 6);
            $table->dateTime('received_at', 6);
            $table->timestamps(6);
            $table->unique(['metric_series_id', 'time_key'], 'metric_sample_time_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('monitor')->dropIfExists('metric_samples');
        Schema::connection('monitor')->dropIfExists('metric_series');
    }
};
