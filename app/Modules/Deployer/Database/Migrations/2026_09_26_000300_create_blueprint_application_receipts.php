<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('deployer')->create('blueprint_application_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('step_id', 64)->unique();
            $table->string('workspace_source_id', 128);
            $table->string('actor_source_id', 128);
            $table->string('project_source_id', 128);
            $table->string('canonical_project_id', 64);
            $table->string('payload_hash', 64);
            $table->json('result');
            $table->timestamp('completed_at');
            $table->timestamps();
            $table->index(['workspace_source_id', 'actor_source_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('deployer')->dropIfExists('blueprint_application_receipts');
    }
};
