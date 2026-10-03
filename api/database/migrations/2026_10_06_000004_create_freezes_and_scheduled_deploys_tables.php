<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Dated deploy freezes per environment, and one-off deploys booked for a later time. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('environment_freezes', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('environment_id')->constrained()->cascadeOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('reason', 255)->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['environment_id', 'ends_at']);
        });

        Schema::create('scheduled_deploys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->timestamp('run_at');
            $table->string('git_ref', 200)->nullable();
            $table->string('status', 16);
            $table->foreignId('build_id')->nullable()->constrained()->nullOnDelete();
            $table->string('result', 255)->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_deploys');
        Schema::dropIfExists('environment_freezes');
    }
};
