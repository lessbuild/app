<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('deployer')->create('product_deletion_activity_claims', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('claim_group_id')->index();
            $table->string('actor_source_id', 128)->nullable();
            $table->string('workspace_source_id', 128)->nullable();
            $table->string('operation', 96);
            $table->string('status', 24)->default('claimed');
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('recovered_at')->nullable();
            $table->string('recovery_reason', 500)->nullable();
            $table->timestamps();
            $table->index(['actor_source_id', 'status']);
            $table->index(['workspace_source_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::connection('deployer')->dropIfExists('product_deletion_activity_claims');
    }
};
