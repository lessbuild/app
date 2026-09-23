<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->boolean('preview_enabled')->default(false);
            $table->string('preview_domain')->nullable();
            $table->unsignedSmallInteger('preview_ttl_hours')->default(72);
        });

        Schema::create('preview_deployments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_repository_id')->constrained('repositories')->cascadeOnDelete();
            $table->foreignId('environment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('website_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('repository_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('pull_request_number');
            $table->string('title')->nullable();
            $table->string('source_branch');
            $table->string('revision', 64);
            $table->string('status', 24)->default('provisioning');
            $table->string('url');
            $table->timestamp('last_activity_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['source_repository_id', 'pull_request_number']);
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preview_deployments');
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn(['preview_enabled', 'preview_domain', 'preview_ttl_hours']);
        });
    }
};
