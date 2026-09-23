<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var string */
    protected $connection = 'monitor';

    public function up(): void
    {
        Schema::connection('monitor')->create('project_connection_incident_outbox_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('event_type', 64);
            $table->unsignedSmallInteger('event_version')->default(1);
            $table->string('source_incident_id', 64);
            $table->string('source_environment_id', 64);
            $table->json('payload');
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at', 6)->nullable();
            $table->timestamp('dispatched_at', 6)->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestamp('last_error_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['event_type', 'source_incident_id'], 'monitor_incident_outbox_event_unique');
            $table->index(['status', 'available_at', 'created_at'], 'monitor_incident_outbox_due_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('monitor')->dropIfExists('project_connection_incident_outbox_events');
    }
};
