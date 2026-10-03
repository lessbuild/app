<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Deploy part 1: repositories that deploy to websites, their builds (one release each), and repository webhook deliveries. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('environments', function (Blueprint $table): void {
            $table->boolean('requires_deployment_approval')->default(false);
        });

        Schema::create('repositories', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('website_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('environment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->string('url', 255);
            $table->string('branch', 255)->default('main');
            $table->string('deployment_root', 512)->nullable();
            $table->text('build_commands')->nullable();
            $table->text('post_deployment_commands')->nullable();
            $table->json('auto_deploy_include_paths')->nullable();
            $table->json('auto_deploy_exclude_paths')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->boolean('webhook_enabled')->default(false);
            $table->timestamp('webhook_last_received_at', 6)->nullable();
            $table->boolean('webhook_pending')->default(false);
            $table->string('webhook_pending_revision', 64)->nullable();
            $table->text('webhook_pending_commit_message')->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->timestamps(6);
            $table->softDeletes('deleted_at', 6);
            $table->index('project_id');
            $table->index('website_id');
        });

        Schema::create('builds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('environment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at', 6)->nullable();
            $table->foreignUlid('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at', 6)->nullable();
            $table->text('approval_note')->nullable();
            $table->string('status', 24)->default('queued');
            $table->string('trigger_source', 16)->default('manual');
            $table->string('revision', 64)->nullable();
            $table->text('commit_message')->nullable();
            $table->json('changed_paths')->nullable();
            $table->text('operator_note')->nullable();
            $table->longText('environment_payload')->nullable();
            $table->unsignedSmallInteger('setup_stage')->default(0);
            $table->string('release_name', 120)->nullable();
            $table->string('release_path', 512)->nullable();
            $table->unsignedBigInteger('remote_process_id')->nullable();
            $table->string('remote_process_path')->nullable();
            $table->longText('log')->nullable();
            $table->text('failure_message')->nullable();
            $table->foreignId('redeployed_from_build_id')->nullable()->constrained('builds')->nullOnDelete();
            $table->foreignId('rolled_back_from_build_id')->nullable()->constrained('builds')->nullOnDelete();
            $table->timestamp('started_at', 6)->nullable();
            $table->timestamp('last_heartbeat_at', 6)->nullable();
            $table->timestamp('activated_at', 6)->nullable();
            $table->timestamp('finished_at', 6)->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->timestamps(6);
            $table->index(['repository_id', 'id']);
            $table->index(['website_id', 'status']);
            $table->index(['status', 'last_heartbeat_at']);
        });

        Schema::create('repository_webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->string('delivery_id');
            $table->string('status', 20);
            $table->string('revision', 64)->nullable();
            $table->text('commit_message')->nullable();
            $table->json('changed_paths')->nullable();
            $table->foreignId('build_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps(6);
            $table->unique(['repository_id', 'delivery_id']);
            $table->index(['repository_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repository_webhook_deliveries');
        Schema::dropIfExists('builds');
        Schema::dropIfExists('repositories');
        Schema::table('environments', fn (Blueprint $table) => $table->dropColumn('requires_deployment_approval'));
    }
};
