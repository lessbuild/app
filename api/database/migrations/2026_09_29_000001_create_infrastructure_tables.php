<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Infrastructure part 1: provider credentials and their connection checks, servers, provisioning logs and server imports. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('providers', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('type', 32);
            $table->string('credential_type', 16)->default('token');
            $table->string('external_id')->nullable();
            $table->text('token');
            $table->string('connection_status', 16)->default('unchecked');
            $table->timestamp('connection_checked_at', 6)->nullable();
            $table->boolean('connection_monitoring_enabled')->default(true);
            $table->unsignedSmallInteger('connection_check_interval_minutes')->default(1440);
            $table->unsignedTinyInteger('connection_failure_threshold')->default(1);
            $table->unsignedSmallInteger('connection_failure_count')->default(0);
            $table->timestamps(6);
            $table->softDeletes('deleted_at', 6);
            $table->index(['account_id', 'type', 'id'], 'providers_account_type_index');
            $table->index(['connection_monitoring_enabled', 'connection_checked_at', 'id'], 'providers_due_index');
        });

        Schema::create('provider_connection_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->boolean('successful');
            $table->string('source', 16);
            $table->string('provider_type', 32);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('duration_ms');
            $table->string('endpoint', 512)->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('checked_at', 6);
            $table->index(['provider_id', 'checked_at', 'id'], 'provider_connection_checks_history_index');
        });

        Schema::create('servers', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 32)->default('app');
            $table->string('name', 64);
            $table->string('display_name', 80)->nullable();
            $table->string('identifier', 100)->nullable();
            $table->string('region')->nullable();
            $table->string('size')->nullable();
            $table->string('image')->nullable();
            $table->string('public_ip', 45)->nullable();
            $table->string('private_ip', 45)->nullable();
            $table->unsignedSmallInteger('ssh_port')->default(22);
            $table->string('ssh_fingerprint')->nullable();
            $table->boolean('ssh_key_owned')->default(true);
            $table->text('ssh_public_key')->nullable();
            $table->text('ssh_private_key')->nullable();
            $table->text('ssh_host_key')->nullable();
            $table->string('ssh_host_fingerprint', 100)->nullable();
            $table->text('password')->nullable();
            $table->text('mysql_root_password')->nullable();
            $table->unsignedSmallInteger('setup_stage')->default(0);
            $table->string('provisioning_status', 20)->default('queued');
            $table->text('provisioning_error')->nullable();
            $table->string('provisioning_failure_phase', 20)->nullable();
            $table->uuid('provisioning_token')->nullable();
            $table->uuid('initialization_token')->nullable();
            $table->unsignedBigInteger('provisioning_process_id')->nullable();
            $table->string('provisioning_process_path')->nullable();
            $table->timestamp('provisioned_at', 6)->nullable();
            $table->longText('recipe_snapshot')->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->timestamps(6);
            $table->index(['account_id', 'name', 'id'], 'servers_account_name_index');
        });

        Schema::create('server_log_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('status', 16);
            $table->longText('log')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('refreshed_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['server_id', 'type']);
        });

        Schema::create('server_import_assessments', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->text('configuration');
            $table->text('report');
            $table->timestamp('expires_at', 6);
            $table->timestamp('consumed_at', 6)->nullable();
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_import_assessments');
        Schema::dropIfExists('server_log_snapshots');
        Schema::dropIfExists('servers');
        Schema::dropIfExists('provider_connection_checks');
        Schema::dropIfExists('providers');
    }
};
