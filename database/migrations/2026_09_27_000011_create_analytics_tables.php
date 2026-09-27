<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Analytics service, ported from the standalone Analytics app. High-volume tables keep bigint ids;
 * column names match the old app so its reporting queries carry over unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_sites', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('environment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            // The tracker's data-site value. Kept unchanged when sites are imported from the old app.
            $table->string('public_id', 32)->unique();
            $table->json('domains');
            $table->json('excluded_paths')->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->timestamp('last_processed_at')->nullable();
            $table->boolean('collection_enabled')->default(true);
            $table->timestamp('collection_paused_at')->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->timestamps();
            $table->index(['project_id', 'collection_enabled']);
        });

        Schema::create('analytics_ingestion_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('analytics_sites')->cascadeOnDelete();
            $table->uuid('batch_id');
            $table->unsignedInteger('event_count')->default(0);
            $table->string('status', 24)->default('pending');
            $table->timestamp('accepted_at');
            $table->timestamp('processed_at')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'batch_id']);
            $table->index(['status', 'accepted_at']);
        });

        Schema::create('analytics_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('analytics_sites')->cascadeOnDelete();
            $table->foreignId('ingestion_batch_id')->nullable()->constrained('analytics_ingestion_batches')->nullOnDelete();
            $table->uuid('event_id');
            $table->string('type', 32);
            $table->timestamp('occurred_at');
            $table->timestamp('received_at');
            $table->string('path', 2048);
            $table->string('referrer_host', 255)->nullable();
            $table->string('utm_source', 100)->nullable();
            $table->string('utm_medium', 100)->nullable();
            $table->string('utm_campaign', 150)->nullable();
            $table->string('device_category', 32)->nullable();
            $table->string('browser', 64)->nullable();
            $table->string('operating_system', 64)->nullable();
            $table->string('visitor_hash', 64)->nullable();
            $table->string('session_id', 64)->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'event_id']);
            $table->index(['site_id', 'occurred_at']);
            $table->index(['site_id', 'type', 'occurred_at']);
            $table->index(['site_id', 'path']);
            $table->index(['site_id', 'visitor_hash', 'occurred_at']);
            $table->index(['site_id', 'session_id', 'occurred_at']);
        });

        Schema::create('analytics_visits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('analytics_sites')->cascadeOnDelete();
            $table->string('visit_key', 128);
            $table->string('visitor_hash', 64)->nullable();
            $table->string('session_id', 64)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('last_seen_at');
            $table->string('landing_path', 2048)->nullable();
            $table->string('exit_path', 2048)->nullable();
            $table->string('entry_referrer_host', 255)->nullable();
            $table->string('entry_utm_source', 100)->nullable();
            $table->string('entry_utm_medium', 100)->nullable();
            $table->string('entry_utm_campaign', 150)->nullable();
            $table->unsignedInteger('pageviews')->default(0);
            $table->unsignedInteger('conversion_count')->default(0);
            $table->timestamps();
            $table->unique(['site_id', 'visit_key']);
            $table->index(['site_id', 'last_seen_at']);
            $table->index(['site_id', 'visitor_hash', 'started_at']);
            $table->index(['site_id', 'entry_utm_source', 'started_at']);
            $table->index(['site_id', 'entry_referrer_host', 'started_at']);
        });

        Schema::create('analytics_goals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('analytics_sites')->cascadeOnDelete();
            $table->string('name');
            $table->string('kind', 24);
            $table->string('match_type', 24)->default('exact');
            $table->string('match_value', 255);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['site_id', 'active']);
        });

        Schema::create('analytics_goal_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('goal_id')->constrained('analytics_goals')->cascadeOnDelete();
            $table->string('kind', 24);
            $table->string('match_type', 24)->default('exact');
            $table->string('match_value', 255);
            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();
            $table->timestamps();
            $table->index(['goal_id', 'effective_from', 'effective_to']);
        });

        Schema::create('analytics_goal_conversions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('analytics_sites')->cascadeOnDelete();
            $table->foreignId('goal_id')->constrained('analytics_goals')->cascadeOnDelete();
            $table->foreignId('goal_version_id')->nullable()->constrained('analytics_goal_versions')->nullOnDelete();
            $table->foreignId('analytics_event_id')->constrained('analytics_events')->cascadeOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained('analytics_visits')->nullOnDelete();
            $table->timestamp('converted_at');
            $table->timestamps();
            $table->unique(['goal_id', 'analytics_event_id']);
            $table->index(['site_id', 'converted_at']);
        });

        Schema::create('analytics_daily_aggregates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('analytics_sites')->cascadeOnDelete();
            $table->date('local_date');
            $table->string('dimension', 32);
            $table->string('dimension_value', 2048)->nullable();
            $table->unsignedBigInteger('pageviews')->default(0);
            $table->unsignedBigInteger('visits')->default(0);
            $table->unsignedBigInteger('visitors')->default(0);
            $table->unsignedBigInteger('conversions')->default(0);
            $table->unsignedInteger('converted_visits')->default(0);
            $table->unsignedBigInteger('bounce_eligible')->default(0);
            $table->unsignedBigInteger('bounces')->default(0);
            $table->timestamps();
            $table->unique(['site_id', 'local_date', 'dimension', 'dimension_value'], 'analytics_daily_aggregate_unique');
            $table->index(['site_id', 'local_date', 'dimension']);
        });

        Schema::create('analytics_exports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('analytics_sites')->cascadeOnDelete();
            $table->foreignUlid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('status', 24)->default('pending');
            $table->json('filters')->nullable();
            $table->string('file_path')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();
            $table->index(['site_id', 'created_at']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        foreach (['analytics_exports', 'analytics_daily_aggregates', 'analytics_goal_conversions', 'analytics_goal_versions', 'analytics_goals', 'analytics_visits', 'analytics_events', 'analytics_ingestion_batches', 'analytics_sites'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
