<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deploy part 4b: configuration as code. A YAML document is planned against the project's environments, frozen in a
 * short-lived review, and applied; objects it manages are "owned", and its deploys run as operations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuration_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('requested_by')->constrained('users')->cascadeOnDelete();
            $table->text('document');
            $table->text('bindings');
            $table->json('summary');
            $table->timestamp('expires_at', 6);
            $table->timestamp('applied_at', 6)->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->timestamps(6);
            $table->index(['project_id', 'id']);
        });

        Schema::create('configuration_ownerships', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('configuration_review_id')->nullable()->constrained()->nullOnDelete();
            $table->string('environment_slug', 100);
            $table->string('kind', 16);
            $table->string('logical_name', 100);
            $table->string('resource_key', 40);
            $table->timestamps(6);
            $table->unique(['project_id', 'environment_slug', 'kind', 'logical_name'], 'configuration_ownerships_identity_unique');
            $table->unique(['kind', 'resource_key'], 'configuration_ownerships_resource_unique');
        });

        Schema::create('configuration_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('configuration_review_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 24)->default('applying');
            $table->timestamp('locally_applied_at', 6)->nullable();
            $table->timestamps(6);
        });

        Schema::create('configuration_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('configuration_application_id')->constrained()->cascadeOnDelete();
            $table->string('environment_slug', 100);
            $table->foreignUlid('environment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 16)->default('deploy');
            $table->string('status', 24)->default('pending');
            $table->char('intent_digest', 64);
            $table->text('payload');
            $table->foreignId('build_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('failure_code', 40)->nullable();
            $table->foreignId('retry_of_operation_id')->nullable()->unique()->constrained('configuration_operations')->nullOnDelete();
            $table->unsignedSmallInteger('retry_sequence')->default(0);
            $table->timestamp('started_at', 6)->nullable();
            $table->timestamp('completed_at', 6)->nullable();
            $table->timestamps(6);
            $table->index(['environment_id', 'kind', 'id']);
            $table->index(['status', 'id']);
        });

        Schema::create('configuration_operation_receipts', function (Blueprint $table): void {
            $table->foreignId('configuration_application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('configuration_operation_id')->constrained()->cascadeOnDelete();
            $table->primary(['configuration_application_id', 'configuration_operation_id'], 'configuration_operation_receipts_primary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuration_operation_receipts');
        Schema::dropIfExists('configuration_operations');
        Schema::dropIfExists('configuration_applications');
        Schema::dropIfExists('configuration_ownerships');
        Schema::dropIfExists('configuration_reviews');
    }
};
