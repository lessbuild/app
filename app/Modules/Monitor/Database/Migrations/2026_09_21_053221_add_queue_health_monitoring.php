<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('monitor')->table('monitors', function (Blueprint $table): void {
            $table->string('queue_name', 120)->nullable();
            $table->json('queue_settings')->nullable();
            $table->char('queue_token_hash', 64)->nullable();
            $table->dateTime('queue_started_at', 6)->nullable();
            $table->unsignedBigInteger('queue_snapshot_id')->nullable();
        });
        Schema::connection('monitor')->create('queue_snapshots', function (Blueprint $table): void {
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
        Schema::connection('monitor')->create('queue_workers', function (Blueprint $table): void {
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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection('monitor')->table('monitors')->where('type', 'queue')->exists() || DB::connection('monitor')->table('queue_snapshots')->exists()
            || DB::connection('monitor')->table('queue_workers')->exists()) {
            throw new RuntimeException('Queue monitoring history exists. Use a forward migration instead.');
        }
        Schema::connection('monitor')->drop('queue_workers');
        Schema::connection('monitor')->drop('queue_snapshots');
        Schema::connection('monitor')->table('monitors', function (Blueprint $table): void {
            $table->dropColumn(['queue_name', 'queue_settings', 'queue_token_hash', 'queue_started_at', 'queue_snapshot_id']);
        });
    }
};
