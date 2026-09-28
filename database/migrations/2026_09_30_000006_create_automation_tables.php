<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Deploy part 6: scheduled deploys, scaling schedules, scheduled tasks with their runs, hibernation, and workflows. */
return new class extends Migration
{
    /**
     * Add hibernation to environments and the workflow document to projects, and create the schedule and task tables.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('environments', function (Blueprint $table): void {
            $table->unsignedSmallInteger('hibernate_after_minutes')->nullable();
            $table->timestamp('last_activity_at', 6)->nullable();
            $table->timestamp('hibernated_at', 6)->nullable();
        });
        Schema::table('projects', function (Blueprint $table): void {
            $table->text('workflow_document')->nullable();
        });

        foreach (['deployment_schedules', 'scaling_schedules'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->id();
                $table->foreignUlid('environment_id')->constrained()->cascadeOnDelete();
                $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('name', 100);
                if ($name === 'scaling_schedules') {
                    $table->unsignedTinyInteger('replicas');
                }
                $table->string('cron_expression', 100);
                $table->string('timezone', 64)->default('UTC');
                $table->boolean('is_enabled')->default(true);
                $table->timestamp('last_run_at', 6)->nullable();
                $table->string('last_result', 255)->nullable();
                $table->timestamps(6);
                $table->index(['environment_id', 'id']);
                $table->index('is_enabled');
            });
        }

        Schema::create('scheduled_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('environment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 100);
            $table->text('command');
            $table->string('cron_expression', 100);
            $table->string('timezone', 64)->default('UTC');
            $table->unsignedSmallInteger('timeout_seconds')->default(300);
            $table->boolean('without_overlapping')->default(true);
            $table->boolean('alert_on_failure')->default(true);
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('last_queued_at', 6)->nullable();
            $table->timestamp('last_finished_at', 6)->nullable();
            $table->string('last_status', 20)->nullable();
            $table->timestamps(6);
            $table->unique(['environment_id', 'name']);
            $table->index('is_enabled');
        });

        Schema::create('scheduled_task_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scheduled_task_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('queued');
            $table->longText('output')->nullable();
            $table->integer('exit_code')->nullable();
            $table->timestamp('started_at', 6)->nullable();
            $table->timestamp('finished_at', 6)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps(6);
            $table->index(['scheduled_task_id', 'id']);
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Drop the automation tables and columns.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('scheduled_task_runs');
        Schema::dropIfExists('scheduled_tasks');
        Schema::dropIfExists('scaling_schedules');
        Schema::dropIfExists('deployment_schedules');
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn('workflow_document'));
        Schema::table('environments', fn (Blueprint $table) => $table->dropColumn(['hibernate_after_minutes', 'last_activity_at', 'hibernated_at']));
    }
};
