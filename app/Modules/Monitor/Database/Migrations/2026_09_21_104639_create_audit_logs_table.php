<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('monitor')->create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('metadata')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 1024)->nullable();
            $table->timestamp('created_at', 6)->useCurrent();
            $table->index(['workspace_id', 'created_at', 'id'], 'audit_logs_workspace_created_index');
            $table->index(['workspace_id', 'action', 'created_at'], 'audit_logs_workspace_action_index');
            $table->index(['subject_type', 'subject_id'], 'audit_logs_subject_index');
        });
    }

    public function down(): void
    {
        Schema::connection('monitor')->dropIfExists('audit_logs');
    }
};
