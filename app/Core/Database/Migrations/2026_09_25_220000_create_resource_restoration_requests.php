<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'core';

    public function up(): void
    {
        Schema::connection('core')->create('resource_restoration_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignUlid('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignUlid('environment_id')->nullable()->constrained('project_environments')->restrictOnDelete();
            $table->foreignUlid('project_resource_id')->constrained('project_resources')->restrictOnDelete();
            $table->string('product', 32);
            $table->string('resource_type', 64);
            $table->string('resource_id', 191);
            $table->string('source_workspace_entity', 64);
            $table->string('source_workspace_id', 191);
            $table->string('source_parent_id', 191)->nullable();
            $table->unsignedBigInteger('expected_revision');
            $table->char('mapping_fingerprint', 64);
            $table->json('mapping_bindings');
            $table->json('requested_states');
            $table->char('payload_hash', 64);
            $table->string('idempotency_key', 100)->unique();
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('lease_token', 64)->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->char('receipt_hash', 64)->nullable();
            $table->unsignedBigInteger('receipt_revision')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('last_error_code', 100)->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'available_at'], 'resource_restoration_due_index');
            $table->index(['status', 'lease_expires_at'], 'resource_restoration_lease_index');
        });
    }

    public function down(): void
    {
        Schema::connection('core')->dropIfExists('resource_restoration_requests');
    }
};
