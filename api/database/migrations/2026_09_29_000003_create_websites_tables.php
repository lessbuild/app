<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Infrastructure part 3: websites on app servers, their domains and provisioning logs. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('websites', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('previous_server_id')->nullable();
            $table->foreignUlid('environment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('url')->unique();
            $table->string('deployment_slug', 32);
            $table->text('env_file')->nullable();
            $table->text('database_password')->nullable();
            $table->unsignedSmallInteger('setup_stage')->default(0);
            $table->string('provisioning_status', 20)->default('queued');
            $table->text('provisioning_error')->nullable();
            $table->text('placement_cleanup_error')->nullable();
            $table->uuid('provisioning_token')->nullable();
            $table->timestamp('provisioned_at', 6)->nullable();
            $table->unsignedTinyInteger('release_retention')->default(5);
            $table->unsignedInteger('log_retention_lines')->default(1000);
            $table->boolean('health_check_enabled')->default(false);
            $table->string('health_check_path')->default('/');
            $table->boolean('health_monitoring_enabled')->default(true);
            $table->unsignedSmallInteger('health_check_interval_minutes')->default(5);
            $table->unsignedTinyInteger('health_failure_threshold')->default(3);
            $table->unsignedSmallInteger('health_failure_count')->default(0);
            $table->string('health_status', 16)->default('unknown');
            $table->timestamp('health_last_checked_at', 6)->nullable();
            $table->text('health_last_error')->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->timestamps(6);
            $table->softDeletes('deleted_at', 6);
            $table->unique(['account_id', 'deployment_slug']);
            $table->index(['server_id', 'provisioning_status'], 'websites_server_status_index');
        });

        Schema::create('website_domains', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('dns_provider_id')->nullable()->constrained('providers')->nullOnDelete();
            $table->string('hostname')->unique();
            $table->string('type', 16)->default('alias');
            $table->string('redirect_url')->nullable();
            $table->boolean('is_temporary')->default(false);
            $table->string('dns_record_id')->nullable();
            $table->string('dns_status', 20)->default('pending');
            $table->string('ssl_status', 20)->default('pending');
            $table->timestamp('certificate_expires_at', 6)->nullable();
            $table->timestamp('last_checked_at', 6)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps(6);
            $table->index(['website_id', 'type']);
            $table->index(['last_checked_at', 'id'], 'website_domains_check_index');
        });

        Schema::create('website_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->longText('log')->nullable();
            $table->timestamps(6);
            $table->unique(['website_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_logs');
        Schema::dropIfExists('website_domains');
        Schema::dropIfExists('websites');
    }
};
