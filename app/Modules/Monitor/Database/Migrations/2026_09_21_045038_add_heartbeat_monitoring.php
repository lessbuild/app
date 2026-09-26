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
        Schema::connection('monitor')->table('monitor_checks', function (Blueprint $table): void {
            $table->boolean('scheduled_slot')->nullable()->default(true);
            $table->dropUnique('monitor_checks_slot_unique');
            $table->unique(['monitor_id', 'config_revision', 'scheduled_at', 'scheduled_slot'], 'monitor_checks_slot_unique');
        });
        Schema::connection('monitor')->table('monitors', function (Blueprint $table): void {
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
        });
        Schema::connection('monitor')->create('heartbeat_runs', function (Blueprint $table): void {
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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection('monitor')->table('monitors')->where('type', 'heartbeat')->exists() || DB::connection('monitor')->table('heartbeat_runs')->exists()
            || DB::connection('monitor')->table('monitor_checks')->whereNull('scheduled_slot')->exists()) {
            throw new RuntimeException('Heartbeat monitoring history exists. Use a forward migration instead.');
        }
        Schema::connection('monitor')->drop('heartbeat_runs');
        Schema::connection('monitor')->table('monitor_checks', function (Blueprint $table): void {
            $table->dropUnique('monitor_checks_slot_unique');
            $table->dropColumn('scheduled_slot');
            $table->unique(['monitor_id', 'config_revision', 'scheduled_at'], 'monitor_checks_slot_unique');
        });
        Schema::connection('monitor')->table('monitors', function (Blueprint $table): void {
            $table->dropColumn(['heartbeat_schedule', 'heartbeat_interval_minutes', 'heartbeat_cron',
                'heartbeat_timezone', 'heartbeat_grace_minutes', 'heartbeat_token_hash', 'heartbeat_sequence',
                'heartbeat_due_at', 'heartbeat_received_at', 'heartbeat_succeeded_at']);
        });
    }
};
