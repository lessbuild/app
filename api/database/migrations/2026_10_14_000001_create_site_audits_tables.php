<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit: a site and the competitors it's compared with, each run of the audit, the journeys a simulated visitor took
 * on every site, the steps of each journey, and the findings with their screenshots and mock-ups.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('url', 2048);
            $table->json('journeys');
            $table->string('schedule', 16)->default('none');
            $table->timestamp('next_run_at')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['project_id', 'created_at']);
            $table->index(['schedule', 'next_run_at']);
        });
        Schema::create('site_audit_competitors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_audit_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('url', 2048);
            $table->string('source', 16);
            $table->string('status', 16);
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->index(['site_audit_id', 'status']);
        });
        Schema::create('site_audit_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_audit_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16);
            $table->string('trigger', 16);
            $table->unsignedTinyInteger('score')->nullable();
            $table->json('scores')->nullable();
            $table->text('summary')->nullable();
            $table->unsignedSmallInteger('pages_visited')->default(0);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->text('error')->nullable();
            $table->foreignUlid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['site_audit_id', 'created_at']);
            $table->index(['project_id', 'created_at']);
        });
        Schema::create('site_audit_journeys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_audit_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_audit_competitor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('site_url', 2048);
            $table->string('goal_key', 40);
            $table->string('goal', 300);
            $table->string('outcome', 16);
            $table->unsignedTinyInteger('score')->nullable();
            $table->unsignedSmallInteger('steps_count')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->text('summary')->nullable();
            $table->json('friction')->nullable();
            $table->timestamps();
            $table->index(['site_audit_run_id', 'site_audit_competitor_id']);
        });
        Schema::create('site_audit_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_audit_journey_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('url', 2048);
            $table->json('action');
            $table->text('thought')->nullable();
            $table->string('screenshot_path')->nullable();
            $table->json('boxes')->nullable();
            $table->json('elements')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamps();
            $table->unique(['site_audit_journey_id', 'position']);
        });
        Schema::create('site_audit_findings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_audit_run_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->string('category', 24);
            $table->string('severity', 16);
            $table->string('effort', 16);
            $table->string('title');
            $table->text('detail');
            $table->text('recommendation');
            $table->string('page_url', 2048)->nullable();
            $table->string('screenshot_path')->nullable();
            $table->json('boxes')->nullable();
            $table->string('mockup_path')->nullable();
            $table->text('competitor_note')->nullable();
            $table->timestamps();
            $table->index(['site_audit_run_id', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_audit_findings');
        Schema::dropIfExists('site_audit_steps');
        Schema::dropIfExists('site_audit_journeys');
        Schema::dropIfExists('site_audit_runs');
        Schema::dropIfExists('site_audit_competitors');
        Schema::dropIfExists('site_audits');
    }
};
