<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_operation_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('environment_resource_id')->constrained()->cascadeOnDelete();
            $table->string('operation', 32);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('status', 24)->default('queued');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamps();
            $table->index(['environment_resource_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index(['status', 'lease_expires_at']);
            $table->index(['operation', 'subject_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_operation_runs');
    }
};
