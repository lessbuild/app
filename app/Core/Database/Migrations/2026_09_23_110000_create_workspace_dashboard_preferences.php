<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'core';

    public function up(): void
    {
        Schema::connection('core')->create('workspace_dashboard_views', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('visibility', 16);
            $table->string('scope_key', 64);
            $table->foreignUlid('owner_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 80);
            $table->json('filters');
            $table->timestamps();
            $table->unique(['workspace_id', 'scope_key', 'name'], 'workspace_dashboard_views_scope_name_unique');
            $table->index(['workspace_id', 'visibility', 'owner_user_id']);
        });

        Schema::connection('core')->create('workspace_dashboard_selections', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('view_id')->nullable()->constrained('workspace_dashboard_views')->nullOnDelete();
            $table->timestamps();
            $table->unique(['workspace_id', 'user_id'], 'workspace_dashboard_selections_user_unique');
        });

        Schema::connection('core')->create('workspace_project_pins', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUlid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('visibility', 16);
            $table->string('scope_key', 64);
            $table->foreignUlid('owner_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['workspace_id', 'project_id', 'scope_key'], 'workspace_project_pins_scope_unique');
            $table->index(['workspace_id', 'visibility', 'owner_user_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('core')->dropIfExists('workspace_project_pins');
        Schema::connection('core')->dropIfExists('workspace_dashboard_selections');
        Schema::connection('core')->dropIfExists('workspace_dashboard_views');
    }
};
