<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monitoring, part 1 (ported from the standalone Monitor app): monitors and their checks, heartbeat and queue
 * signals, incidents, alert destinations and deliveries, and maintenance windows. Workspaces become accounts;
 * Monitor's application/environment become the project's environment. Columns otherwise match the old app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitors', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('environment_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('type')->default('http');
            $table->text('request_url')->nullable();
            $table->text('bearer_token')->nullable();
            $table->string('method')->default('GET');
            $table->unsignedSmallInteger('status_min')->default(200);
            $table->unsignedSmallInteger('status_max')->default(299);
            $table->text('body_contains')->nullable();
            $table->unsignedInteger('max_duration_ms')->nullable();
            $table->unsignedSmallInteger('timeout_seconds')->default(10);
            $table->unsignedSmallInteger('interval_minutes')->default(5);
            $table->unsignedTinyInteger('trigger_checks')->default(2);
            $table->unsignedTinyInteger('recovery_checks')->default(2);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('state_version')->default(0);
            $table->unsignedInteger('config_revision')->default(0);
            $table->string('health')->default('unknown');
            $table->unsignedInteger('failure_streak')->default(0);
            $table->unsignedInteger('recovery_streak')->default(0);
            $table->timestamp('next_check_at', 6)->nullable();
            $table->timestamp('checked_at', 6)->nullable();
            $table->timestamp('last_scheduled_at', 6)->nullable();
            $table->json('observation')->nullable();
            $table->text('hostname')->nullable();
            $table->string('dns_record_type', 5)->nullable();
            $table->string('dns_match', 10)->nullable();
            $table->text('dns_expected')->nullable();
            $table->unsignedSmallInteger('tls_port')->nullable();
            $table->unsignedTinyInteger('tls_expiry_days')->nullable();
            $table->unsignedSmallInteger('tcp_port')->nullable();
            $table->string('heartbeat_schedule', 8)->nullable();
            $table->unsignedInteger('heartbeat_interval_minutes')->nullable();
            $table->string('heartbeat_cron', 100)->nullable();
            $table->string('heartbeat_timezone', 64)->nullable();
            $table->unsignedInteger('heartbeat_grace_minutes')->nullable();
            $table->char('heartbeat_token_hash', 64)->nullable();
            $table->unsignedBigInteger('heartbeat_sequence')->nullable();
            $table->dateTime('heartbeat_due_at', 6)->nullable();
            $table->dateTime('heartbeat_received_at', 6)->nullable();
            $table->dateTime('heartbeat_succeeded_at', 6)->nullable();
            $table->string('queue_name', 120)->nullable();
            $table->json('queue_settings')->nullable();
            $table->char('queue_token_hash', 64)->nullable();
            $table->dateTime('queue_started_at', 6)->nullable();
            $table->unsignedBigInteger('queue_snapshot_id')->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->timestamps(6);
            $table->softDeletes('deleted_at', 6);
            $table->index(['enabled', 'next_check_at', 'id'], 'monitors_due_index');
            $table->index(['environment_id', 'deleted_at', 'id'], 'monitors_environment_index');
        });

        Schema::create('monitor_checks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('config_revision');
            $table->string('location', 80);
            $table->string('status')->default('queued');
            $table->string('outcome')->nullable();
            $table->string('reason')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->double('duration_ms')->nullable();
            $table->double('dns_ms')->nullable();
            $table->double('connect_ms')->nullable();
            $table->double('ttfb_ms')->nullable();
            $table->unsignedInteger('skipped_intervals')->default(0);
            $table->json('details')->nullable();
            $table->longText('evidence')->nullable();
            $table->boolean('scheduled_slot')->nullable()->default(true);
            $table->timestamp('scheduled_at', 6);
            $table->timestamp('started_at', 6)->nullable();
            $table->timestamp('finished_at', 6)->nullable();
            $table->timestamp('lease_until', 6)->nullable();
            $table->uuid('processing_token')->nullable();
            $table->string('queue_job_uuid', 36)->nullable(); // compared with jobs.job_uuid, a string
            $table->timestamps(6);
            $table->unique(['monitor_id', 'config_revision', 'scheduled_at', 'scheduled_slot'], 'monitor_checks_slot_unique');
            $table->index(['monitor_id', 'scheduled_at', 'id'], 'monitor_checks_history_index');
            $table->index(['status', 'lease_until', 'id'], 'monitor_checks_recovery_index');
        });

        Schema::create('heartbeat_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->uuid('run_id');
            $table->unsignedInteger('config_revision');
            $table->string('status', 16);
            $table->string('terminal_signal', 8)->nullable();
            $table->dateTime('started_at', 6)->nullable();
            $table->dateTime('finished_at', 6)->nullable();
            $table->dateTime('deadline_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['monitor_id', 'run_id']);
            $table->index(['monitor_id', 'status', 'deadline_at', 'id'], 'heartbeat_deadlines_index');
            $table->index(['monitor_id', 'id'], 'heartbeat_history_index');
        });

        Schema::create('queue_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->uuid('snapshot_id');
            $table->unsignedInteger('config_revision');
            $table->char('payload_hash', 64);
            $table->dateTime('observed_at', 6);
            $table->dateTime('received_at', 6);
            $table->dateTime('valid_until', 6);
            $table->boolean('applied');
            $table->unsignedInteger('pending');
            $table->unsignedInteger('delayed')->nullable();
            $table->unsignedInteger('reserved')->nullable();
            $table->unsignedInteger('failed')->nullable();
            $table->unsignedInteger('oldest_wait_seconds')->nullable();
            $table->timestamps(6);
            $table->unique(['monitor_id', 'snapshot_id']);
            $table->index(['monitor_id', 'id'], 'queue_snapshot_history_index');
            $table->index(['monitor_id', 'config_revision', 'observed_at', 'id'], 'queue_snapshot_chart_index');
        });

        Schema::create('queue_workers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->uuid('worker_id');
            $table->unsignedInteger('config_revision');
            $table->unsignedInteger('last_sequence');
            $table->string('status', 8);
            $table->uuid('job_id')->nullable();
            $table->dateTime('job_started_at', 6)->nullable();
            $table->dateTime('last_seen_at', 6);
            $table->timestamps(6);
            $table->unique(['monitor_id', 'worker_id']);
            $table->index(['monitor_id', 'config_revision', 'status', 'last_seen_at', 'id'], 'queue_worker_liveness_index');
            $table->index(['monitor_id', 'id'], 'queue_worker_history_index');
        });

        Schema::create('incidents', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('project_id')->nullable()->constrained()->cascadeOnDelete();
            // Alert rules arrive with telemetry (Monitoring part 3), which adds the foreign key.
            $table->unsignedBigInteger('alert_rule_id')->nullable();
            $table->foreignId('monitor_id')->nullable()->constrained()->cascadeOnDelete();
            $table->boolean('active_slot')->nullable()->default(true);
            $table->string('title', 255);
            $table->string('status', 30)->default('open');
            $table->unsignedInteger('state_version')->default(0);
            $table->json('rule_snapshot');
            $table->json('opening_observation');
            $table->json('latest_observation');
            $table->timestamp('opened_at', 6);
            $table->timestamp('last_breached_at', 6);
            $table->timestamp('acknowledged_at', 6)->nullable();
            $table->foreignUlid('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at', 6)->nullable();
            $table->string('closure_reason', 40)->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->timestamps(6);
            // Closed incidents release the nullable slot, so the database allows one active incident per rule or monitor.
            $table->unique(['alert_rule_id', 'active_slot'], 'incidents_active_rule_unique');
            $table->unique(['monitor_id', 'active_slot'], 'incidents_active_monitor_unique');
            $table->index(['status', 'opened_at', 'id'], 'incidents_inbox_index');
            $table->index(['project_id', 'opened_at', 'id'], 'incidents_project_index');
            $table->index(['account_id', 'opened_at', 'id'], 'incidents_account_index');
        });

        Schema::create('incident_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps(6);
            $table->index(['incident_id', 'id']);
        });

        Schema::create('alert_destinations', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('account_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('type', 16);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('state_version')->default(0);
            $table->unsignedInteger('target_revision')->default(0);
            $table->foreignUlid('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('endpoint_url')->nullable();
            $table->text('signing_secret')->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->timestamps(6);
            $table->softDeletes('deleted_at', 6);
            $table->index(['account_id', 'created_at', 'id'], 'destinations_account_index');
        });

        Schema::create('alert_destination_monitor', function (Blueprint $table): void {
            $table->foreignId('alert_destination_id')->constrained()->cascadeOnDelete();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->boolean('opened')->default(true);
            $table->boolean('recovered')->default(true);
            $table->primary(['alert_destination_id', 'monitor_id']);
            $table->index('monitor_id');
        });

        Schema::create('alert_deliveries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('alert_destination_id')->constrained()->cascadeOnDelete();
            $table->foreignId('incident_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 16);
            $table->unsignedInteger('target_revision');
            $table->text('payload');
            $table->string('status', 16)->default('queued');
            $table->unsignedInteger('generation')->default(0);
            $table->unsignedInteger('attempt_count')->default(0);
            $table->unsignedTinyInteger('cycle_attempts')->default(0);
            $table->string('queue_job_uuid', 36)->nullable(); // compared with jobs.job_uuid, a string
            $table->uuid('processing_token')->nullable();
            $table->timestamp('next_attempt_at', 6)->nullable();
            $table->timestamp('accepted_at', 6)->nullable();
            $table->timestamp('failed_at', 6)->nullable();
            $table->string('last_error_code', 48)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->timestamps(6);
            $table->unique(['incident_id', 'alert_destination_id', 'event'], 'alert_deliveries_event_unique');
            $table->index(['status', 'next_attempt_at', 'id'], 'alert_deliveries_due_index');
            $table->index(['alert_destination_id', 'created_at', 'id'], 'alert_deliveries_destination_index');
            $table->index(['account_id', 'created_at', 'id'], 'alert_deliveries_account_index');
        });

        Schema::create('alert_delivery_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('alert_delivery_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('status', 16);
            $table->string('error_code', 48)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->timestamp('started_at', 6);
            $table->timestamp('finished_at', 6)->nullable();
            $table->unique(['alert_delivery_id', 'number'], 'alert_attempt_number_unique');
        });

        Schema::create('maintenance_windows', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->text('reason')->nullable();
            $table->timestamp('starts_at', 6);
            $table->timestamp('ends_at', 6);
            $table->timestamps(6);
            $table->index(['account_id', 'starts_at', 'ends_at', 'id'], 'maintenance_windows_account_time_index');
        });
    }

    public function down(): void
    {
        foreach (['maintenance_windows', 'alert_delivery_attempts', 'alert_deliveries', 'alert_destination_monitor', 'alert_destinations', 'incident_activities', 'incidents', 'queue_workers', 'queue_snapshots', 'heartbeat_runs', 'monitor_checks', 'monitors'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
