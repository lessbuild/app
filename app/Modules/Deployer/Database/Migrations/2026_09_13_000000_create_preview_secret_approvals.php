<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preview_deployments', function (Blueprint $table): void {
            $table->foreignId('source_environment_id')->nullable()->after('source_repository_id')->constrained('environments')->nullOnDelete();
        });

        Schema::create('preview_secret_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('preview_deployment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_environment_id')->nullable()->constrained('environments')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revision', 64);
            $table->json('variable_versions');
            $table->timestamp('approved_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['preview_deployment_id', 'revision']);
            $table->index(['preview_deployment_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preview_secret_approvals');
        Schema::table('preview_deployments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_environment_id');
        });
    }
};
