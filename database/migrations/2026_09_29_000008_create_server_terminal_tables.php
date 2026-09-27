<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The troubleshooting terminal: a root shell on a server, relayed through encrypted frames by a queued broker. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_terminal_sessions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64);
            $table->string('status', 20)->default('connecting');
            $table->string('close_reason', 40)->nullable();
            $table->unsignedSmallInteger('columns')->default(80);
            $table->unsignedSmallInteger('rows')->default(24);
            $table->unsignedInteger('input_sequence')->default(0);
            $table->unsignedInteger('output_sequence')->default(0);
            $table->timestamp('expires_at', 6);
            $table->timestamp('idle_expires_at', 6);
            $table->timestamp('broker_seen_at', 6)->nullable();
            $table->timestamp('connected_at', 6)->nullable();
            $table->timestamp('closed_at', 6)->nullable();
            $table->timestamps(6);
            $table->index(['server_id', 'status']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('server_terminal_frames', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('server_terminal_session_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 3);
            $table->unsignedInteger('sequence');
            $table->text('payload');
            $table->unsignedInteger('bytes');
            $table->timestamp('created_at', 6)->nullable();
            $table->unique(['server_terminal_session_id', 'direction', 'sequence'], 'server_terminal_frames_sequence_unique');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_terminal_frames');
        Schema::dropIfExists('server_terminal_sessions');
    }
};
