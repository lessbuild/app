<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'core';

    public function up(): void
    {
        Schema::connection('core')->create('identity_projection_operations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 24);
            $table->ulid('canonical_id');
            $table->string('product', 32);
            $table->string('token', 64);
            $table->string('status', 24)->default('processing');
            $table->timestamps();
            $table->unique(['kind', 'canonical_id', 'product'], 'identity_projection_operation_unique');
        });
        Schema::connection('core')->create('deletion_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 24);
            $table->ulid('target_id');
            $table->json('workspace_ids');
            $table->json('identity_bindings');
            $table->char('intent_hash', 64);
            $table->char('receipt_token_hash', 64);
            $table->string('idempotency_key', 100)->unique();
            $table->string('phase', 24)->default('prepare');
            $table->string('status', 24)->default('pending');
            $table->string('last_error_code', 100)->nullable();
            $table->json('retained')->nullable();
            $table->timestamp('accepted_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['kind', 'target_id']);
            $table->index(['status', 'phase']);
        });
        Schema::connection('core')->create('deletion_steps', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('deletion_request_id')->constrained('deletion_requests')->restrictOnDelete();
            $table->string('product', 32);
            $table->string('kind', 24);
            $table->string('source_id', 191);
            $table->json('target');
            $table->char('payload_hash', 64);
            $table->string('phase', 24)->default('prepare');
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('lease_token', 64)->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->string('last_error_code', 100)->nullable();
            $table->json('retained')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['product', 'kind', 'source_id']);
            $table->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('core')->dropIfExists('deletion_steps');
        Schema::connection('core')->dropIfExists('deletion_requests');
        Schema::connection('core')->dropIfExists('identity_projection_operations');
    }
};
