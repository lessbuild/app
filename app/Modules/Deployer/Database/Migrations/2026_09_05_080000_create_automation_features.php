<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->text('workflow_yaml')->nullable()->after('preset');
        });
        Schema::table('environments', function (Blueprint $table): void {
            $table->unsignedInteger('desired_replicas')->default(1)->after('maximum_replicas');
            $table->timestamp('last_activity_at')->nullable()->after('hibernate_after_minutes');
            $table->timestamp('hibernated_at')->nullable()->after('last_activity_at');
        });
        Schema::create('deployment_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('cron_expression', 100);
            $table->string('timezone', 64)->default('UTC');
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
            $table->index(['is_enabled', 'last_run_at']);
        });
        Schema::create('scaling_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedInteger('replicas');
            $table->string('cron_expression', 100);
            $table->string('timezone', 64)->default('UTC');
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
            $table->index(['is_enabled', 'last_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scaling_schedules');
        Schema::dropIfExists('deployment_schedules');
        Schema::table('environments', fn (Blueprint $table) => $table->dropColumn(['desired_replicas', 'last_activity_at', 'hibernated_at']));
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn('workflow_yaml'));
    }
};
