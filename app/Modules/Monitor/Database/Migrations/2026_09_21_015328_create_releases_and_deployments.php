<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('monitor')->create('releases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('service', 100)->nullable();
            $table->string('service_namespace', 100)->nullable();
            $table->string('version', 128);
            $table->char('service_hash', 64);
            $table->char('version_hash', 64);
            $table->timestamp('first_seen_at', 6)->nullable();
            $table->timestamp('last_seen_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['application_id', 'service_hash', 'version_hash'], 'releases_identity_unique');
            $table->index(['application_id', 'created_at', 'id'], 'releases_application_created_index');
        });
        Schema::connection('monitor')->create('deployments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('release_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ingest_token_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('deployment_key');
            $table->char('payload_hash', 64);
            $table->string('source', 16);
            $table->string('commit_sha', 64)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('deployed_at', 6);
            $table->timestamps(6);
            $table->unique(['environment_id', 'deployment_key'], 'deployments_environment_key_unique');
            $table->index(['environment_id', 'deployed_at', 'id'], 'deployments_environment_time_index');
            $table->index(['release_id', 'deployed_at', 'id'], 'deployments_release_time_index');
        });
        Schema::connection('monitor')->table('telemetry_events', function (Blueprint $table): void {
            $table->foreignId('release_id')->nullable()->constrained()->nullOnDelete();
            $table->index(['release_id', 'environment_id', 'occurred_at', 'id'], 'events_release_environment_time_index');
        });
    }

    public function down(): void
    {
        Schema::connection('monitor')->table('telemetry_events', function (Blueprint $table): void {
            $table->dropIndex('events_release_environment_time_index');
            $table->dropConstrainedForeignId('release_id');
        });
        Schema::connection('monitor')->dropIfExists('deployments');
        Schema::connection('monitor')->dropIfExists('releases');
    }
};
