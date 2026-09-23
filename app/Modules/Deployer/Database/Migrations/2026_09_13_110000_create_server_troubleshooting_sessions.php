<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_troubleshooting_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('status', 32)->index();
            $table->char('grant_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('idle_expires_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('close_reason', 64)->nullable();
            $table->timestamps();

            $table->index(['server_id', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_troubleshooting_sessions');
    }
};
