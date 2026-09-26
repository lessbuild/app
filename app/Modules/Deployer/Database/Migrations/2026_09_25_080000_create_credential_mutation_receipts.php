<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('deployer')->create('credential_mutation_receipts', function (Blueprint $table): void {
            $table->uuid('operation_id')->primary();
            $table->string('actor_source_id', 128);
            $table->string('workspace_source_id', 128);
            $table->string('action', 16);
            $table->char('input_hash', 64);
            $table->string('credential_key', 190);
            $table->string('credential_type', 80);
            $table->string('credential_name', 120);
            $table->timestamp('created_at');
            $table->index(['actor_source_id', 'workspace_source_id', 'created_at'], 'deployer_credential_mutation_actor_workspace_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('deployer')->dropIfExists('credential_mutation_receipts');
    }
};
