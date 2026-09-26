<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'core';

    public function up(): void
    {
        Schema::connection('core')->create('workspace_feature_rollouts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('feature', 64);
            $table->boolean('enabled');
            $table->timestamps();
            $table->unique(['workspace_id', 'feature'], 'workspace_feature_rollout_unique');
        });

        Schema::connection('core')->create('workspace_feature_rollout_changes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUlid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('feature', 64);
            $table->boolean('previous_enabled')->nullable();
            $table->boolean('enabled')->nullable();
            $table->timestamp('created_at');
            $table->index(['workspace_id', 'created_at'], 'workspace_rollout_change_history');
        });

        Schema::connection('core')->create('workspace_feature_rollout_metrics', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('feature', 64);
            $table->date('day');
            foreach (['exposed', 'completed', 'degraded', 'failed', 'rejected', 'held'] as $outcome) {
                $table->unsignedBigInteger($outcome.'_count')->default(0);
            }
            $table->timestamp('last_exposed_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'feature', 'day'], 'workspace_rollout_metric_daily');
        });
    }

    public function down(): void
    {
        Schema::connection('core')->dropIfExists('workspace_feature_rollout_metrics');
        Schema::connection('core')->dropIfExists('workspace_feature_rollout_changes');
        Schema::connection('core')->dropIfExists('workspace_feature_rollouts');
    }
};
