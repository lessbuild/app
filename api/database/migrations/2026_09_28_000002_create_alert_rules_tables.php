<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monitoring, part 3 (ported from the standalone Monitor app): service level objectives, alert rules on telemetry,
 * their routing to alert destinations and their escalation steps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_level_objectives', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('environment_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('indicator', 16);
            $table->string('service', 100)->nullable();
            $table->string('route')->nullable();
            $table->decimal('target', 6, 3);
            $table->unsignedSmallInteger('window_days')->default(30);
            $table->double('latency_threshold_ms')->nullable();
            $table->unsignedSmallInteger('status_min')->default(200);
            $table->unsignedSmallInteger('status_max')->default(399);
            $table->boolean('enabled')->default(true);
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->timestamps(6);
            $table->softDeletes('deleted_at', 6);
            $table->index(['environment_id', 'enabled', 'id'], 'slo_environment_index');
            $table->index(['indicator', 'enabled', 'id'], 'slo_indicator_index');
        });

        Schema::create('alert_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('environment_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('metric', 32);
            $table->string('service', 100)->nullable();
            $table->string('match_text', 120)->nullable();
            $table->decimal('threshold', 20, 3);
            $table->double('numeric_threshold')->nullable();
            $table->foreignId('metric_series_id')->nullable()->constrained('metric_series')->nullOnDelete();
            $table->string('aggregation', 8)->nullable();
            $table->string('comparison', 4)->nullable();
            $table->unsignedInteger('freshness_seconds')->nullable();
            $table->foreignId('service_level_objective_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('window_minutes');
            $table->unsignedInteger('minimum_samples');
            $table->unsignedTinyInteger('trigger_checks');
            $table->unsignedTinyInteger('recovery_checks');
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('state_version')->default(0);
            $table->string('evaluation_state', 16)->default('warming');
            $table->unsignedInteger('breach_streak')->default(0);
            $table->unsignedInteger('recovery_streak')->default(0);
            $table->timestamp('monitoring_since', 6);
            $table->timestamp('next_evaluation_at', 6);
            $table->timestamp('evaluated_until', 6)->nullable();
            $table->timestamp('checked_at', 6)->nullable();
            $table->json('observation')->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->timestamps(6);
            $table->softDeletes('deleted_at', 6);
            $table->index(['enabled', 'next_evaluation_at', 'id'], 'alerts_due_index');
            $table->index(['environment_id', 'created_at', 'id'], 'alerts_environment_index');
            $table->index(['service_level_objective_id', 'enabled'], 'alerts_slo_enabled_index');
        });

        Schema::create('alert_destination_alert_rule', function (Blueprint $table): void {
            $table->foreignId('alert_destination_id')->constrained()->cascadeOnDelete();
            $table->foreignId('alert_rule_id')->constrained()->cascadeOnDelete();
            $table->boolean('opened')->default(true);
            $table->boolean('recovered')->default(true);
            $table->primary(['alert_destination_id', 'alert_rule_id']);
            $table->index(['alert_rule_id', 'alert_destination_id'], 'alert_routes_rule_index');
        });

        Schema::create('alert_escalations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('alert_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('alert_destination_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('delay_minutes');
            $table->unsignedTinyInteger('position');
            $table->boolean('enabled')->default(true);
            $table->timestamps(6);
            $table->unique(['alert_rule_id', 'alert_destination_id'], 'alert_escalations_rule_destination_unique');
            $table->unique(['alert_rule_id', 'position'], 'alert_escalations_rule_position_unique');
            $table->index(['alert_destination_id', 'enabled', 'delay_minutes'], 'alert_escalations_destination_index');
        });

        Schema::table('incidents', function (Blueprint $table): void {
            $table->foreign('alert_rule_id')->references('id')->on('alert_rules')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->dropForeign(['alert_rule_id']);
        });
        foreach (['alert_escalations', 'alert_destination_alert_rule', 'alert_rules', 'service_level_objectives'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
