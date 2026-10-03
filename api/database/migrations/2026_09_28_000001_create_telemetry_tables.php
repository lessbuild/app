<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monitoring, part 2 (ported from the standalone Monitor app): ingest keys and receipts, telemetry events,
 * issues, releases, deployments and metric samples. Monitor's application becomes the project and its workspace
 * the account; columns otherwise match the old app so the importer can copy rows across.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('environments', function (Blueprint $table): void {
            $table->unsignedBigInteger('telemetry_event_count')->default(0);
            $table->dateTime('telemetry_last_received_at', 6)->nullable();
        });

        Schema::create('ingest_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('environment_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->char('token_hash', 64)->unique();
            $table->string('prefix', 16);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->timestamps();
            $table->index(['environment_id', 'revoked_at']);
        });

        Schema::create('ingest_receipts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('environment_id')->nullable()->constrained()->nullOnDelete();
            $table->char('receipt_key', 64)->unique();
            $table->char('payload_fingerprint', 64);
            $table->string('source', 32);
            $table->string('status', 16);
            $table->unsignedInteger('event_count');
            $table->unsignedInteger('accepted_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedBigInteger('attempt_count')->default(1);
            $table->unsignedInteger('processing_attempts')->default(0);
            $table->unsignedInteger('generation')->default(1);
            $table->unsignedInteger('recovery_count')->default(0);
            $table->string('queue_job_uuid', 36)->nullable();
            $table->uuid('processing_token')->nullable();
            $table->timestamp('received_at', 6);
            $table->timestamp('last_received_at', 6);
            $table->timestamp('processing_started_at', 6)->nullable();
            $table->timestamp('next_attempt_at', 6)->nullable();
            $table->timestamp('processed_at', 6)->nullable();
            $table->timestamp('failed_at', 6)->nullable();
            $table->string('last_error_code', 40)->nullable();
            $table->timestamps(6);
            $table->index(['environment_id', 'received_at', 'id'], 'receipts_environment_received_index');
            $table->index(['status', 'next_attempt_at', 'id'], 'receipts_recovery_index');
        });

        Schema::create('ingest_payloads', function (Blueprint $table): void {
            $table->foreignUlid('ingest_receipt_id')->primary()->constrained()->cascadeOnDelete();
            $table->longText('payload');
            $table->timestamps(6);
        });

        // One row per processed delivery: the per-receipt ledger behind the monthly event allowance. Billing usage
        // for Stripe is recorded separately in usage_records.
        Schema::create('telemetry_usage_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('environment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('ingest_receipt_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('source', 32);
            $table->unsignedInteger('event_count');
            $table->timestamp('received_at', 6);
            $table->timestamps(6);
            $table->index(['account_id', 'received_at'], 'usage_account_received_index');
        });

        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->string('service', 100)->nullable();
            $table->string('service_namespace', 100)->nullable();
            $table->string('version', 128);
            $table->char('service_hash', 64);
            $table->char('version_hash', 64);
            $table->timestamp('first_seen_at', 6)->nullable();
            $table->timestamp('last_seen_at', 6)->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->timestamps(6);
            $table->unique(['project_id', 'service_hash', 'version_hash'], 'releases_identity_unique');
            $table->index(['project_id', 'created_at', 'id'], 'releases_project_created_index');
        });

        Schema::create('issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('environment_id')->nullable()->constrained()->nullOnDelete();
            $table->char('fingerprint', 64);
            $table->string('type', 32)->default('exception');
            $table->string('severity', 16)->default('error');
            $table->string('status', 16)->default('open');
            $table->string('title');
            $table->string('location')->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->unsignedInteger('affected_users')->default(0);
            $table->timestamp('first_seen_at', 6);
            $table->timestamp('last_seen_at', 6);
            $table->text('details')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignUlid('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at', 6)->nullable();
            $table->timestamp('snoozed_until', 6)->nullable();
            $table->unsignedInteger('state_version')->default(0);
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->timestamps();
            $table->unique(['project_id', 'fingerprint']);
            $table->index(['status', 'last_seen_at']);
            $table->index(['status', 'snoozed_until', 'id'], 'issues_snooze_due_index');
            $table->index(['project_id', 'first_seen_at'], 'issues_project_first_seen_index');
            $table->index(['project_id', 'resolved_at'], 'issues_project_resolved_index');
        });

        Schema::create('issue_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 32);
            $table->json('metadata')->nullable();
            $table->text('note')->nullable();
            $table->timestamps(6);
            $table->index(['issue_id', 'id']);
        });

        Schema::create('telemetry_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('environment_id')->constrained()->cascadeOnDelete();
            $table->char('dedupe_key', 64)->unique();
            $table->string('trace_id', 64)->nullable();
            $table->string('span_id', 32)->nullable();
            $table->string('parent_span_id', 32)->nullable();
            $table->string('type', 32);
            $table->string('severity', 16)->default('info');
            $table->string('name')->nullable();
            $table->string('route')->nullable();
            $table->string('service')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->decimal('duration_ms', 20, 6)->nullable();
            $table->json('attributes')->nullable();
            $table->json('payload')->nullable();
            $table->dateTime('occurred_at', 6);
            $table->string('timestamp_unix_nano', 20)->nullable();
            $table->string('end_timestamp_unix_nano', 20)->nullable();
            $table->foreignId('issue_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('release_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['environment_id', 'occurred_at']);
            $table->index(['type', 'occurred_at']);
            $table->index('trace_id');
            $table->index(['issue_id', 'occurred_at', 'id'], 'events_issue_occurred_index');
            $table->index(['release_id', 'environment_id', 'occurred_at', 'id'], 'events_release_environment_time_index');
        });

        Schema::create('telemetry_event_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('environment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('ingest_receipt_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('telemetry_event_id')->nullable()->constrained()->nullOnDelete();
            $table->char('dedupe_key', 64)->unique();
            $table->unsignedTinyInteger('version');
            $table->char('payload_fingerprint', 64)->nullable();
            $table->timestamps(6);
        });

        Schema::create('deployments', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('environment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('release_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ingest_token_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('deployment_key');
            $table->char('payload_hash', 64);
            $table->string('source', 16);
            $table->string('commit_sha', 64)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('deployed_at', 6);
            $table->timestamps(6);
            $table->unique(['environment_id', 'deployment_key'], 'deployments_environment_key_unique');
            $table->index(['environment_id', 'deployed_at', 'id'], 'deployments_environment_time_index');
            $table->index(['release_id', 'deployed_at', 'id'], 'deployments_release_time_index');
        });

        Schema::create('metric_series', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('environment_id')->constrained()->cascadeOnDelete();
            $table->char('identity_hash', 64);
            $table->string('source', 24);
            $table->string('name');
            $table->string('resource_label');
            $table->string('unit');
            $table->string('kind', 32);
            $table->string('temporality', 16)->nullable();
            $table->boolean('monotonic')->default(false);
            $table->json('descriptor');
            $table->dateTime('first_received_at', 6);
            $table->dateTime('last_received_at', 6);
            $table->timestamps(6);
            $table->unique(['environment_id', 'identity_hash'], 'metric_series_identity_unique');
            $table->index(['environment_id', 'last_received_at', 'id'], 'metric_series_catalog_index');
        });

        Schema::create('metric_samples', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('metric_series_id')->constrained('metric_series')->cascadeOnDelete();
            $table->foreignId('telemetry_event_id')->constrained()->cascadeOnDelete();
            $table->char('time_key', 20);
            $table->char('start_time_key', 20)->nullable();
            $table->char('value_hash', 64);
            $table->string('value_text', 64)->nullable();
            $table->double('value')->nullable();
            $table->string('state', 24);
            $table->dateTime('occurred_at', 6);
            $table->dateTime('received_at', 6);
            $table->timestamps(6);
            $table->unique(['metric_series_id', 'time_key'], 'metric_sample_time_unique');
        });
    }

    public function down(): void
    {
        foreach (['metric_samples', 'metric_series', 'deployments', 'telemetry_event_identities', 'telemetry_events', 'issue_activities', 'issues', 'releases', 'telemetry_usage_entries', 'ingest_payloads', 'ingest_receipts', 'ingest_tokens'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('environments', function (Blueprint $table): void {
            $table->dropColumn(['telemetry_event_count', 'telemetry_last_received_at']);
        });
    }
};
