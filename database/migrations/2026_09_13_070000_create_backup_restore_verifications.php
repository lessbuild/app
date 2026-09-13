<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_restore_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('website_backup_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('snapshot_id', 64);
            $table->string('target_type', 32)->default('same_server_temporary');
            $table->string('overwrite_mode', 24)->default('never');
            $table->string('status', 20)->default('queued');
            $table->string('integrity_status', 20)->default('pending');
            $table->string('smoke_status', 20)->default('pending');
            $table->string('cleanup_status', 20)->default('pending');
            $table->string('failure_stage', 32)->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['website_backup_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_restore_verifications');
    }
};
