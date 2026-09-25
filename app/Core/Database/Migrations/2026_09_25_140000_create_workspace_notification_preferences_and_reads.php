<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('core')->create('workspace_notification_reads', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('notification_key', 64);
            $table->timestamp('read_at');
            $table->timestamps();
            $table->unique(['workspace_id', 'user_id', 'notification_key'], 'workspace_notification_reads_unique');
            $table->index(['workspace_id', 'user_id', 'read_at'], 'workspace_notification_reads_user_index');
        });

        Schema::connection('core')->create('workspace_notification_preferences', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('product', 24);
            $table->string('severity', 24);
            $table->char('scope_key', 64);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['workspace_id', 'user_id', 'scope_key'], 'workspace_notification_preferences_unique');
            $table->index(['workspace_id', 'user_id', 'product', 'severity'], 'workspace_notification_preferences_filter_index');
        });
    }

    public function down(): void
    {
        Schema::connection('core')->dropIfExists('workspace_notification_preferences');
        Schema::connection('core')->dropIfExists('workspace_notification_reads');
    }
};
