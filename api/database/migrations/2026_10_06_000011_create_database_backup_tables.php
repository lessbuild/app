<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Database servers can run MySQL or PostgreSQL, keep continuous backups (WAL-G base backups plus the write-ahead log or
 * binary log) in a backup destination, and be restored to a moment in time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->string('database_engine', 16)->nullable();
        });

        Schema::create('database_backup_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('server_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('backup_destination_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('retention_days');
            $table->timestamp('enabled_at');
            $table->foreignId('setup_execution_id')->nullable()->constrained('server_command_executions')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('database_restores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->timestamp('restore_to');
            $table->foreignId('execution_id')->nullable()->constrained('server_command_executions')->nullOnDelete();
            $table->foreignUlid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_restores');
        Schema::dropIfExists('database_backup_plans');
        Schema::table('servers', function (Blueprint $table): void {
            $table->dropColumn('database_engine');
        });
    }
};
