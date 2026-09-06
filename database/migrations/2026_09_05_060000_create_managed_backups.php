<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_destinations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->string('endpoint');
            $table->string('bucket');
            $table->string('region')->default('us-east-1');
            $table->text('access_key');
            $table->text('secret_key');
            $table->text('repository_password');
            $table->string('path_prefix')->default('buildpusher');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_verified_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('website_backup_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->foreignId('backup_destination_id')->constrained()->cascadeOnDelete();
            $table->string('frequency', 16)->default('daily');
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->time('run_at')->default('02:00');
            $table->unsignedSmallInteger('retention_count')->default(14);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_queued_at')->nullable();
            $table->timestamps();
            $table->unique(['website_id', 'backup_destination_id']);
        });

        Schema::create('website_backups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->foreignId('backup_destination_id')->constrained()->restrictOnDelete();
            $table->foreignId('website_backup_schedule_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('queued');
            $table->string('snapshot_id')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['website_id', 'status']);
        });

        Schema::create('backup_restores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('website_backup_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('queued');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_restores');
        Schema::dropIfExists('website_backups');
        Schema::dropIfExists('website_backup_schedules');
        Schema::dropIfExists('backup_destinations');
    }
};
