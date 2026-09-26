<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_metrics', function (Blueprint $table): void {
            $table->unsignedTinyInteger('cpu_percent')->nullable()->after('load_15m');
            $table->unsignedBigInteger('network_rx_bytes')->nullable()->after('disk_percent');
            $table->unsignedBigInteger('network_tx_bytes')->nullable()->after('network_rx_bytes');
            $table->unsignedBigInteger('disk_read_bytes')->nullable()->after('network_tx_bytes');
            $table->unsignedBigInteger('disk_write_bytes')->nullable()->after('disk_read_bytes');
            $table->unsignedInteger('process_count')->nullable()->after('disk_write_bytes');
        });

        Schema::create('metric_alert_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('server_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('metric', 32);
            $table->string('operator', 4)->default('gte');
            $table->decimal('threshold', 14, 2);
            $table->unsignedTinyInteger('consecutive_breaches')->default(3);
            $table->unsignedTinyInteger('breach_count')->default(0);
            $table->unsignedSmallInteger('cooldown_minutes')->default(30);
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_alerting')->default(false);
            $table->timestamp('last_evaluated_at')->nullable();
            $table->timestamp('last_triggered_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'is_enabled']);
        });

        Schema::create('scheduled_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->text('command');
            $table->string('cron_expression', 100);
            $table->string('timezone', 64)->default('UTC');
            $table->unsignedSmallInteger('timeout_seconds')->default(300);
            $table->boolean('without_overlapping')->default(true);
            $table->boolean('alert_on_failure')->default(true);
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('last_queued_at')->nullable();
            $table->timestamp('last_finished_at')->nullable();
            $table->string('last_status', 20)->nullable();
            $table->timestamps();
            $table->unique(['environment_id', 'name']);
            $table->index(['is_enabled', 'last_queued_at']);
        });

        Schema::create('scheduled_task_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scheduled_task_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('queued');
            $table->text('output')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();
            $table->index(['scheduled_task_id', 'created_at']);
        });

        Schema::table('environment_processes', function (Blueprint $table): void {
            $table->string('restart_policy', 20)->default('always')->after('replicas');
            $table->unsignedSmallInteger('restart_delay_seconds')->default(5)->after('restart_policy');
        });
    }

    public function down(): void
    {
        Schema::table('environment_processes', fn (Blueprint $table) => $table->dropColumn(['restart_policy', 'restart_delay_seconds']));
        Schema::dropIfExists('scheduled_task_runs');
        Schema::dropIfExists('scheduled_tasks');
        Schema::dropIfExists('metric_alert_rules');
        Schema::table('server_metrics', fn (Blueprint $table) => $table->dropColumn([
            'cpu_percent', 'network_rx_bytes', 'network_tx_bytes', 'disk_read_bytes', 'disk_write_bytes', 'process_count',
        ]));
    }
};
