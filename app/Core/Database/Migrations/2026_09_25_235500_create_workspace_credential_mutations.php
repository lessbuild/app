<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('core')->create('workspace_credential_mutations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUlid('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->string('product', 32);
            $table->string('action', 16);
            $table->char('idempotency_key_hash', 64);
            $table->char('input_hash', 64);
            $table->string('status', 24)->default('pending');
            $table->string('credential_key', 190)->nullable();
            $table->string('credential_type', 80)->nullable();
            $table->string('credential_name', 120)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['actor_id', 'workspace_id', 'product', 'action', 'idempotency_key_hash'], 'workspace_credential_idempotency_unique');
            $table->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('core')->dropIfExists('workspace_credential_mutations');
    }
};
