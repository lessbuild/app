<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var string */
    protected $connection = 'core';

    public function up(): void
    {
        Schema::connection('core')->create('project_connection_deliveries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_connection_id')->constrained('project_connections')->restrictOnDelete();
            $table->string('source_event_id', 26);
            $table->string('event_type', 100);
            $table->unsignedSmallInteger('event_version');
            $table->json('payload');
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->string('last_error_code', 100)->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamps();
            $table->unique(['source_event_id', 'project_connection_id'], 'project_connection_delivery_source_unique');
            $table->index(['status', 'available_at'], 'project_connection_delivery_due_index');
        });
    }

    public function down(): void
    {
        Schema::connection('core')->dropIfExists('project_connection_deliveries');
    }
};
