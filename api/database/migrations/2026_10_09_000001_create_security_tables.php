<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Security's findings (one row per problem, kept up to date by each scan) and the scans that produce them. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_scans', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 24);
            $table->string('status', 16);
            $table->unsignedInteger('findings_count')->default(0);
            $table->text('error')->nullable();
            $table->foreignUlid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'kind', 'created_at']);
        });
        Schema::create('security_findings', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->string('source', 24);
            $table->string('scope', 120);
            $table->string('fingerprint', 64);
            $table->string('severity', 16);
            $table->string('title');
            $table->text('detail')->nullable();
            $table->string('subject')->nullable();
            $table->string('url', 2048)->nullable();
            $table->text('fix')->nullable();
            $table->json('data')->nullable();
            $table->string('status', 16)->default('open');
            $table->text('ignored_reason')->nullable();
            $table->foreignUlid('ignored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'fingerprint']);
            $table->index(['project_id', 'status', 'severity']);
            $table->index(['project_id', 'source', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_findings');
        Schema::dropIfExists('security_scans');
    }
};
