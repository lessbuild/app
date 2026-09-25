<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'core';

    public function up(): void
    {
        Schema::connection('core')->create('project_blueprints', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignUlid('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->unsignedInteger('latest_version')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('core')->create('project_blueprint_versions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_blueprint_id')->constrained('project_blueprints')->cascadeOnDelete();
            $table->foreignUlid('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->json('definition');
            $table->char('definition_hash', 64);
            $table->timestamps();
            $table->unique(['project_blueprint_id', 'version'], 'blueprint_version_unique');
        });
        Schema::connection('core')->create('project_blueprint_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignUlid('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignUlid('project_blueprint_version_id')->constrained('project_blueprint_versions')->restrictOnDelete();
            $table->foreignUlid('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->char('intent_hash', 64);
            $table->json('environment_bindings');
            $table->string('status', 24)->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'requested_by_user_id', 'idempotency_key'], 'blueprint_run_idempotency');
            $table->index(['status', 'updated_at']);
        });
        Schema::connection('core')->create('project_blueprint_steps', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_blueprint_run_id')->constrained('project_blueprint_runs')->cascadeOnDelete();
            $table->string('product', 32);
            $table->json('target');
            $table->json('configuration');
            $table->json('native_authority');
            $table->char('core_binding_hash', 64);
            $table->char('payload_hash', 64);
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('generation')->default(0);
            $table->string('lease_token', 64)->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->string('last_error_code', 100)->nullable();
            $table->json('result')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['project_blueprint_run_id', 'product'], 'blueprint_run_product');
            $table->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        foreach (['project_blueprint_steps', 'project_blueprint_runs', 'project_blueprint_versions', 'project_blueprints'] as $table) {
            Schema::connection('core')->dropIfExists($table);
        }
    }
};
