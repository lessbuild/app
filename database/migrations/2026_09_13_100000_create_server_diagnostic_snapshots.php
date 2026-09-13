<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_diagnostic_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('server_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('queued');
            $table->json('checks')->nullable();
            $table->string('failure_stage', 20)->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('attempt')->default(1);
            $table->uuid('attempt_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'lease_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_diagnostic_snapshots');
    }
};
