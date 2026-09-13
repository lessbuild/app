<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_troubleshooting_frames', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('server_troubleshooting_session_id')
                ->constrained('server_troubleshooting_sessions')
                ->cascadeOnDelete();
            $table->string('direction', 16);
            $table->unsignedBigInteger('sequence');
            $table->text('payload');
            $table->unsignedInteger('payload_bytes');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['server_troubleshooting_session_id', 'direction', 'sequence'],
                'server_troubleshooting_frames_session_direction_sequence_unique',
            );
            $table->index(
                ['server_troubleshooting_session_id', 'direction', 'sent_at', 'sequence'],
                'server_troubleshooting_frames_pending_input_index',
            );
            $table->index(
                ['server_troubleshooting_session_id', 'direction', 'acknowledged_at', 'sequence'],
                'server_troubleshooting_frames_pending_output_index',
            );
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_troubleshooting_frames');
    }
};
