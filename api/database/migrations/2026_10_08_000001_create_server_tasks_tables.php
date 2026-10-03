<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What runs on a server besides its websites: scheduled commands (cron), long-running processes kept alive by
 * Supervisor, and firewall rules. Each is applied over SSH by a queued job and remembers whether that worked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_cron_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('command', 1000);
            $table->string('user', 32);
            $table->string('frequency', 100);
            $table->string('status', 16)->default('pending');
            $table->text('error')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('server_processes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('command', 1000);
            $table->string('directory', 255)->nullable();
            $table->string('user', 32);
            $table->unsignedTinyInteger('processes')->default(1);
            $table->unsignedSmallInteger('stop_wait_seconds')->default(10);
            $table->string('status', 16)->default('pending');
            $table->text('error')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('server_firewall_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('port', 11);
            $table->string('protocol', 3)->default('tcp');
            $table->string('source', 43)->nullable();
            $table->string('status', 16)->default('pending');
            $table->text('error')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_firewall_rules');
        Schema::dropIfExists('server_processes');
        Schema::dropIfExists('server_cron_jobs');
    }
};
