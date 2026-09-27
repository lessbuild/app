<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Infrastructure part 4b: offsite website backups with restic to S3-compatible storage, restores, and restore verification. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_destinations', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->string('storage_provider', 32)->default('s3_compatible');
            $table->string('endpoint');
            $table->string('bucket', 63);
            $table->string('region', 64)->default('us-east-1');
            $table->text('access_key');
            $table->text('secret_key');
            $table->text('repository_password');
            $table->string('path_prefix', 120)->default('buildpusher');
            $table->timestamp('last_verified_at', 6)->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->timestamps(6);
            $table->index('account_id');
        });

        Schema::create('website_backup_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->foreignId('backup_destination_id')->constrained()->cascadeOnDelete();
            $table->string('frequency', 16)->default('daily');
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->string('run_at', 5)->default('02:00');
            $table->unsignedSmallInteger('retention_count')->default(14);
            $table->timestamp('last_queued_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['website_id', 'backup_destination_id']);
        });

        Schema::create('website_backups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->foreignId('backup_destination_id')->constrained()->restrictOnDelete();
            $table->foreignId('website_backup_schedule_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('queued');
            $table->string('snapshot_id', 64)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamp('https_verified_at', 6)->nullable();
            $table->timestamp('started_at', 6)->nullable();
            $table->timestamp('completed_at', 6)->nullable();
            $table->text('error')->nullable();
            $table->timestamps(6);
            $table->index(['website_id', 'status']);
        });

        Schema::create('backup_restores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('website_backup_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('queued');
            $table->timestamp('started_at', 6)->nullable();
            $table->timestamp('completed_at', 6)->nullable();
            $table->text('error')->nullable();
            $table->timestamps(6);
        });

        Schema::create('backup_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('website_backup_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('snapshot_id', 64);
            $table->string('status', 20)->default('queued');
            $table->string('integrity_status', 20)->default('pending');
            $table->string('smoke_status', 20)->default('pending');
            $table->string('cleanup_status', 20)->default('pending');
            $table->string('failure_stage', 32)->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamp('started_at', 6)->nullable();
            $table->timestamp('completed_at', 6)->nullable();
            $table->text('error')->nullable();
            $table->timestamps(6);
            $table->index(['website_backup_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_verifications');
        Schema::dropIfExists('backup_restores');
        Schema::dropIfExists('website_backups');
        Schema::dropIfExists('website_backup_schedules');
        Schema::dropIfExists('backup_destinations');
    }
};
