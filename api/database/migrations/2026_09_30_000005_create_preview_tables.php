<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Deploy part 5: pull-request previews, set up per source repository, and the secrets approved for them. */
return new class extends Migration
{
    /**
     * Add the preview settings to repositories and create the preview tables.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table): void {
            $table->boolean('previews_enabled')->default(false);
            $table->string('preview_domain', 200)->nullable();
            $table->unsignedSmallInteger('preview_ttl_hours')->default(72);
            $table->text('preview_initialization_command')->nullable();
        });

        // Preview statuses ("preview_source_unverified") are longer than push statuses.
        Schema::table('repository_webhook_deliveries', function (Blueprint $table): void {
            $table->string('status', 32)->change();
        });

        Schema::create('previews', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_repository_id')->constrained('repositories')->cascadeOnDelete();
            $table->foreignUlid('source_environment_id')->nullable()->constrained('environments')->nullOnDelete();
            $table->foreignUlid('environment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('website_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('repository_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('pull_request_number');
            $table->string('title')->nullable();
            $table->string('source_branch');
            $table->string('revision', 64);
            $table->string('status', 16);
            $table->string('url')->nullable();
            $table->timestamp('initialized_at', 6)->nullable();
            $table->timestamp('last_activity_at', 6);
            $table->timestamp('closed_at', 6)->nullable();
            $table->string('cleanup_status', 16)->nullable();
            $table->text('cleanup_error')->nullable();
            $table->unsignedSmallInteger('cleanup_attempts')->default(0);
            $table->timestamps(6);
            $table->unique(['source_repository_id', 'pull_request_number']);
            $table->index(['project_id', 'status']);
            $table->index(['status', 'last_activity_at']);
        });

        Schema::create('preview_secret_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('preview_id')->constrained()->cascadeOnDelete();
            $table->string('revision', 64);
            $table->foreignUlid('source_environment_id')->constrained('environments')->cascadeOnDelete();
            $table->foreignUlid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('variable_versions');
            $table->timestamp('approved_at', 6);
            $table->timestamp('revoked_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['preview_id', 'revision']);
        });
    }

    /**
     * Drop the preview tables and settings.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('preview_secret_approvals');
        Schema::dropIfExists('previews');
        Schema::table('repository_webhook_deliveries', fn (Blueprint $table) => $table->string('status', 20)->change());
        Schema::table('repositories', fn (Blueprint $table) => $table->dropColumn(['previews_enabled', 'preview_domain', 'preview_ttl_hours', 'preview_initialization_command']));
    }
};
