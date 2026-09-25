<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('analytics')->create('analytics_deletion_tombstones', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 24);
            $table->string('source_id', 64);
            $table->string('canonical_id', 64)->nullable();
            $table->string('request_id', 64);
            $table->string('prepare_step_id', 64);
            $table->string('payload_hash', 64);
            $table->string('status', 24)->default('fencing');
            $table->timestamps();
            $table->unique(['kind', 'source_id']);
            $table->index(['request_id', 'status']);
            $table->index(['kind', 'canonical_id']);
        });

        Schema::connection('analytics')->create('analytics_deletion_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 24);
            $table->string('source_id', 64);
            $table->string('request_id', 64);
            $table->string('step_id', 64);
            $table->string('payload_hash', 64);
            $table->string('phase', 24);
            $table->string('status', 24);
            $table->json('retained')->nullable();
            $table->timestamps();
            $table->unique(['kind', 'source_id']);
        });

        Schema::connection('analytics')->create('analytics_deletion_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tombstone_id')->constrained('analytics_deletion_tombstones')->cascadeOnDelete();
            $table->string('disk', 64);
            $table->string('path', 255);
            $table->string('status', 24)->default('pending');
            $table->timestamps();
            $table->unique(['tombstone_id', 'disk', 'path'], 'analytics_deletion_file_unique');
            $table->index(['tombstone_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::connection('analytics')->dropIfExists('analytics_deletion_files');
        Schema::connection('analytics')->dropIfExists('analytics_deletion_receipts');
        Schema::connection('analytics')->dropIfExists('analytics_deletion_tombstones');
    }
};
