<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('monitor')->create('alert_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('metric', 40);
            $table->string('service', 100)->nullable();
            $table->decimal('threshold', 15, 3);
            $table->unsignedSmallInteger('window_minutes');
            $table->unsignedInteger('minimum_samples');
            $table->unsignedTinyInteger('trigger_checks');
            $table->unsignedTinyInteger('recovery_checks');
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('state_version')->default(0);
            $table->string('evaluation_state', 30)->default('warming');
            $table->unsignedTinyInteger('breach_streak')->default(0);
            $table->unsignedTinyInteger('recovery_streak')->default(0);
            $table->timestamp('monitoring_since', 6);
            $table->timestamp('next_evaluation_at', 6);
            $table->timestamp('evaluated_until', 6)->nullable();
            $table->timestamp('checked_at', 6)->nullable();
            $table->json('observation')->nullable();
            $table->timestamps(6);
            $table->softDeletes('deleted_at', 6);
            $table->index(['enabled', 'next_evaluation_at', 'id'], 'alerts_due_index');
            $table->index(['environment_id', 'created_at', 'id'], 'alerts_environment_index');
        });

        Schema::connection('monitor')->create('incidents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('alert_rule_id')->constrained()->cascadeOnDelete();
            $table->boolean('active_slot')->nullable()->default(true);
            $table->string('title', 120);
            $table->string('status', 30)->default('open');
            $table->unsignedInteger('state_version')->default(0);
            $table->json('rule_snapshot');
            $table->json('opening_observation');
            $table->json('latest_observation');
            $table->timestamp('opened_at', 6);
            $table->timestamp('last_breached_at', 6);
            $table->timestamp('acknowledged_at', 6)->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at', 6)->nullable();
            $table->string('closure_reason', 40)->nullable();
            $table->timestamps(6);
            // Closed incidents release the nullable slot; the database enforces one active incident per rule.
            $table->unique(['alert_rule_id', 'active_slot'], 'incidents_active_rule_unique');
            $table->index(['status', 'opened_at', 'id'], 'incidents_inbox_index');
        });

        Schema::connection('monitor')->create('incident_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps(6);
            $table->index(['incident_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::connection('monitor')->dropIfExists('incident_activities');
        Schema::connection('monitor')->dropIfExists('incidents');
        Schema::connection('monitor')->dropIfExists('alert_rules');
    }
};
