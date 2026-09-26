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
        Schema::connection('monitor')->create('telemetry_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->char('dedupe_key', 64)->unique();
            $table->string('trace_id', 64)->nullable();
            $table->string('span_id', 32)->nullable();
            $table->string('parent_span_id', 32)->nullable();
            $table->string('type', 32);
            $table->string('severity', 16)->default('info');
            $table->string('name')->nullable();
            $table->string('route')->nullable();
            $table->string('service')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->decimal('duration_ms', 10, 3)->nullable();
            $table->json('attributes')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['environment_id', 'occurred_at']);
            $table->index(['type', 'occurred_at']);
            $table->index('trace_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('monitor')->dropIfExists('telemetry_events');
    }
};
