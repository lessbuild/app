<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('analytics')->create('site_deletion_operations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('site_source_id', 191)->unique();
            $table->string('workspace_source_id', 191);
            $table->string('requester_source_id', 191);
            $table->string('canonical_workspace_id', 26)->nullable();
            $table->string('canonical_requester_id', 26)->nullable();
            $table->char('payload_hash', 64);
            $table->string('status', 24)->default('fencing');
            $table->string('last_error_code', 100)->nullable();
            $table->json('file_manifest')->nullable();
            $table->timestamp('manifest_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_source_id', 'status']);
            $table->index(['requester_source_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::connection('analytics')->dropIfExists('site_deletion_operations');
    }
};
