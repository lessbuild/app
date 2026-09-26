<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('monitor')->create('monitors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('type')->default('http');
            $table->text('request_url');
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
            $table->timestamps(6);
            $table->softDeletes('deleted_at', 6);
            $table->index(['enabled', 'next_check_at', 'id'], 'monitors_due_index');
            $table->index(['environment_id', 'deleted_at', 'id'], 'monitors_environment_index');
        });
        Schema::connection('monitor')->create('monitor_checks', function (Blueprint $table): void {
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
            $table->timestamp('scheduled_at', 6);
            $table->timestamp('started_at', 6)->nullable();
            $table->timestamp('finished_at', 6)->nullable();
            $table->timestamp('lease_until', 6)->nullable();
            $table->uuid('processing_token')->nullable();
            $table->uuid('queue_job_uuid')->nullable();
            $table->timestamps(6);
            $table->unique(['monitor_id', 'config_revision', 'scheduled_at'], 'monitor_checks_slot_unique');
            $table->index(['monitor_id', 'scheduled_at', 'id'], 'monitor_checks_history_index');
            $table->index(['status', 'lease_until', 'id'], 'monitor_checks_recovery_index');
        });
        Schema::connection('monitor')->table('incidents', function (Blueprint $table): void {
            $table->unsignedBigInteger('alert_rule_id')->nullable()->change();
            $table->foreignId('monitor_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unique(['monitor_id', 'active_slot'], 'incidents_active_monitor_unique');
        });
        Schema::connection('monitor')->create('alert_destination_monitor', function (Blueprint $table): void {
            $table->foreignId('alert_destination_id')->constrained()->cascadeOnDelete();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->boolean('opened')->default(true);
            $table->boolean('recovered')->default(true);
            $table->primary(['alert_destination_id', 'monitor_id']);
            $table->index('monitor_id');
        });
    }

    public function down(): void
    {
        if (DB::connection('monitor')->table('monitors')->exists()) {
            throw new RuntimeException('Uptime history exists. Use a forward migration instead of deleting monitor data.');
        }
        Schema::connection('monitor')->dropIfExists('alert_destination_monitor');
        Schema::connection('monitor')->table('incidents', function (Blueprint $table): void {
            $table->dropUnique('incidents_active_monitor_unique');
            $table->dropConstrainedForeignId('monitor_id');
            $table->unsignedBigInteger('alert_rule_id')->nullable(false)->change();
        });
        Schema::connection('monitor')->dropIfExists('monitor_checks');
        Schema::connection('monitor')->dropIfExists('monitors');
    }
};
