<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('monitor')->table('issues', function (Blueprint $table): void {
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at', 6)->nullable();
            $table->timestamp('snoozed_until', 6)->nullable();
            $table->unsignedInteger('state_version')->default(0);
            $table->timestamp('first_seen_at', 6)->change();
            $table->timestamp('last_seen_at', 6)->change();
            $table->index(['status', 'snoozed_until', 'id'], 'issues_snooze_due_index');
        });
        Schema::connection('monitor')->table('telemetry_events', function (Blueprint $table): void {
            $table->foreignId('issue_id')->nullable()->constrained()->nullOnDelete();
            $table->index(['issue_id', 'occurred_at', 'id'], 'events_issue_occurred_index');
        });
        Schema::connection('monitor')->create('issue_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 32);
            $table->json('metadata')->nullable();
            $table->text('note')->nullable();
            $table->timestamps(6);
            $table->index(['issue_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('monitor')->dropIfExists('issue_activities');
        Schema::connection('monitor')->table('telemetry_events', function (Blueprint $table): void {
            $table->dropIndex('events_issue_occurred_index');
            $table->dropConstrainedForeignId('issue_id');
        });
        Schema::connection('monitor')->table('issues', function (Blueprint $table): void {
            $table->dropIndex('issues_snooze_due_index');
            $table->dropConstrainedForeignId('assignee_id');
            $table->dropColumn(['resolved_at', 'snoozed_until', 'state_version']);
        });
    }
};
