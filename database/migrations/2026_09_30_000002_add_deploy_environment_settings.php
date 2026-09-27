<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Deploy part 3: each environment's deployment controls and runtime, its variables (versioned), processes and resources. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('environments', function (Blueprint $table): void {
            $table->timestamp('deployment_locked_at', 6)->nullable();
            $table->foreignUlid('deployment_locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('deployment_lock_reason', 500)->nullable();
            $table->json('deployment_window_days')->nullable();
            $table->string('deployment_window_start', 5)->nullable();
            $table->string('deployment_window_end', 5)->nullable();
            $table->string('deployment_window_timezone', 64)->nullable();
            $table->string('deployment_strategy', 16)->default('blue_green');
            $table->unsignedTinyInteger('rolling_pause_seconds')->default(2);
            $table->boolean('automatic_rollback')->default(false);
            $table->unsignedSmallInteger('post_deployment_observation_minutes')->nullable();
            $table->string('runtime_type', 16)->default('php');
            $table->string('runtime_version', 20)->nullable();
            $table->text('build_command')->nullable();
            $table->text('start_command')->nullable();
            $table->unsignedSmallInteger('container_port')->nullable();
            $table->string('dockerfile_path')->nullable();
            $table->unsignedSmallInteger('minimum_replicas')->default(1);
            $table->unsignedSmallInteger('maximum_replicas')->default(1);
            $table->unsignedSmallInteger('desired_replicas')->default(1);
        });

        Schema::create('environment_variables', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('environment_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('key', 255);
            $table->text('value');
            $table->boolean('is_secret')->default(true);
            $table->string('scope', 10)->default('runtime');
            $table->unsignedInteger('current_version')->default(1);
            $table->timestamp('rotated_at', 6)->nullable();
            $table->timestamp('rotation_due_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['environment_id', 'key']);
        });

        Schema::create('environment_variable_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('environment_variable_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version');
            $table->text('value');
            $table->timestamps(6);
            $table->unique(['environment_variable_id', 'version']);
        });

        Schema::create('environment_processes', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('environment_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('type', 12);
            $table->text('command');
            $table->unsignedSmallInteger('replicas')->default(1);
            $table->string('restart_policy', 12)->default('always');
            $table->unsignedSmallInteger('restart_delay_seconds')->default(5);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps(6);
            $table->unique(['environment_id', 'name']);
        });

        Schema::create('environment_resources', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('environment_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('type', 16);
            $table->boolean('is_managed')->default(false);
            $table->text('configuration')->nullable();
            $table->string('status', 16)->default('ready');
            $table->timestamps(6);
            $table->unique(['environment_id', 'name']);
        });

        Schema::table('builds', function (Blueprint $table): void {
            $table->foreignId('automatic_rollback_build_id')->nullable()->constrained('builds')->nullOnDelete();
            $table->unsignedSmallInteger('observation_minutes')->nullable();
            $table->string('observation_status', 16)->nullable();
            $table->timestamp('observation_deadline_at', 6)->nullable();
            $table->text('observation_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('builds', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('automatic_rollback_build_id');
            $table->dropColumn(['observation_minutes', 'observation_status', 'observation_deadline_at', 'observation_error']);
        });
        Schema::dropIfExists('environment_resources');
        Schema::dropIfExists('environment_processes');
        Schema::dropIfExists('environment_variable_versions');
        Schema::dropIfExists('environment_variables');
        Schema::table('environments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('deployment_locked_by');
            $table->dropColumn([
                'deployment_locked_at', 'deployment_lock_reason', 'deployment_window_days', 'deployment_window_start', 'deployment_window_end',
                'deployment_window_timezone', 'deployment_strategy', 'rolling_pause_seconds', 'automatic_rollback', 'post_deployment_observation_minutes',
                'runtime_type', 'runtime_version', 'build_command', 'start_command', 'container_port', 'dockerfile_path', 'minimum_replicas',
                'maximum_replicas', 'desired_replicas',
            ]);
        });
    }
};
