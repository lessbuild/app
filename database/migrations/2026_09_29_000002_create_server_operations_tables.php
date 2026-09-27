<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Infrastructure part 2: commands run on servers, resource metrics, and diagnostic reports. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_command_executions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('rerun_from_execution_id')->nullable()->constrained('server_command_executions')->nullOnDelete();
            $table->text('command');
            $table->string('status', 16);
            $table->longText('output')->nullable();
            $table->integer('exit_code')->nullable();
            $table->timestamp('started_at', 6)->nullable();
            $table->timestamp('finished_at', 6)->nullable();
            $table->timestamps(6);
            $table->index(['server_id', 'status', 'id'], 'server_command_executions_server_status_index');
            $table->index(['status', 'created_at', 'id'], 'server_command_executions_prune_index');
        });

        Schema::create('server_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->decimal('load_1m', 8, 2);
            $table->decimal('load_5m', 8, 2);
            $table->decimal('load_15m', 8, 2);
            $table->unsignedTinyInteger('cpu_percent');
            $table->unsignedTinyInteger('memory_percent');
            $table->unsignedTinyInteger('disk_percent');
            $table->unsignedBigInteger('network_rx_bytes')->default(0);
            $table->unsignedBigInteger('network_tx_bytes')->default(0);
            $table->unsignedBigInteger('disk_read_bytes')->default(0);
            $table->unsignedBigInteger('disk_write_bytes')->default(0);
            $table->unsignedInteger('process_count')->default(0);
            $table->unsignedBigInteger('uptime_seconds');
            $table->timestamp('recorded_at', 6);
            $table->index(['server_id', 'recorded_at'], 'server_metrics_history_index');
        });

        Schema::create('server_diagnostic_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('server_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 16);
            $table->json('checks')->nullable();
            $table->string('failure_stage', 32)->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('attempt')->default(0);
            $table->uuid('attempt_token')->nullable();
            $table->timestamp('lease_expires_at', 6)->nullable();
            $table->timestamp('started_at', 6)->nullable();
            $table->timestamp('finished_at', 6)->nullable();
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_diagnostic_snapshots');
        Schema::dropIfExists('server_metrics');
        Schema::dropIfExists('server_command_executions');
    }
};
