<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('environment_resource_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('active_connections')->nullable();
            $table->unsignedBigInteger('slow_queries')->nullable();
            $table->json('schema_tables')->nullable();
            $table->timestamp('collected_at');
            $table->index(['environment_resource_id', 'collected_at']);
        });

        Schema::create('database_users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('environment_resource_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('username');
            $table->text('password');
            $table->string('privilege', 20)->default('read');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->unique(['environment_resource_id', 'username']);
        });

        Schema::create('database_clones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_resource_id')->constrained('environment_resources')->cascadeOnDelete();
            $table->foreignId('target_resource_id')->constrained('environment_resources')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('queued');
            $table->text('error')->nullable();
            $table->unsignedBigInteger('transferred_bytes')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_clones');
        Schema::dropIfExists('database_users');
        Schema::dropIfExists('database_snapshots');
    }
};
