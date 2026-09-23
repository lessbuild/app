<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Core identity and project data live in the separately configured Core database.
     *
     * @var string
     */
    protected $connection = 'core';

    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('email_normalized')->nullable()->index();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->timestamp('password_set_at')->nullable();
            $table->string('auth_type')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->json('preferences')->nullable();
            $table->string('status', 24)->default('active');
            $table->rememberToken();
            $table->timestamps();
            $table->index(['status', 'email_verified_at']);
        });

        Schema::create('user_identities', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider', 48);
            $table->string('provider_user_id', 191);
            $table->string('provider_email')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('status', 24)->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_user_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('passkeys', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('credential_id')->unique();
            $table->json('credential');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->index('user_id');
        });

        Schema::create('workspaces', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->string('slug', 120)->unique();
            $table->string('status', 24)->default('active');
            $table->json('settings')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'archived_at']);
        });

        Schema::create('workspace_memberships', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->restrictOnDelete();
            $table->string('role', 32)->default('member');
            $table->string('status', 24)->default('active');
            $table->foreignUlid('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('workspace_invitations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUlid('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email');
            $table->string('email_normalized')->index();
            $table->string('role', 32);
            $table->string('token_hash', 64)->unique();
            $table->string('status', 24)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'email_normalized', 'status']);
        });

        Schema::create('workspace_product_access', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('membership_id')->constrained('workspace_memberships')->cascadeOnDelete();
            $table->string('product', 24);
            $table->string('role', 32)->default('member');
            $table->string('status', 24)->default('active');
            $table->foreignUlid('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['membership_id', 'product']);
            $table->index(['product', 'status']);
        });

        Schema::create('projects', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('slug', 120);
            $table->string('status', 24)->default('active');
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'slug']);
            $table->index(['workspace_id', 'status']);
        });

        Schema::create('project_memberships', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->restrictOnDelete();
            $table->string('role', 32)->default('member');
            $table->string('status', 24)->default('active');
            $table->foreignUlid('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('project_environments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('slug', 120);
            $table->string('environment_type', 32)->default('custom');
            $table->string('status', 24)->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'slug']);
            $table->index(['project_id', 'environment_type', 'status']);
        });

        Schema::create('project_products', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('product', 24);
            $table->string('status', 24)->default('inactive');
            $table->foreignUlid('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->string('last_error_code', 100)->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'product']);
            $table->index(['product', 'status']);
        });

        Schema::create('project_resources', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignUlid('environment_id')->nullable()->constrained('project_environments')->nullOnDelete();
            $table->string('product', 24);
            $table->string('resource_type', 100);
            $table->string('resource_id', 191);
            $table->string('resource_public_id', 191)->nullable();
            $table->string('name')->nullable();
            $table->string('status', 24)->default('active');
            $table->timestamp('mapped_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['product', 'resource_type', 'resource_id']);
            $table->index(['project_id', 'environment_id', 'product']);
            $table->index(['product', 'resource_public_id']);
        });

        Schema::create('project_connections', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignUlid('source_resource_id')->constrained('project_resources')->restrictOnDelete();
            $table->foreignUlid('target_resource_id')->constrained('project_resources')->restrictOnDelete();
            $table->foreignUlid('source_environment_id')->nullable()->constrained('project_environments')->nullOnDelete();
            $table->foreignUlid('target_environment_id')->nullable()->constrained('project_environments')->nullOnDelete();
            $table->json('capabilities');
            $table->string('status', 24)->default('pending');
            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_succeeded_at')->nullable();
            $table->string('last_error_code', 100)->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['source_resource_id', 'target_resource_id']);
            $table->index(['project_id', 'status']);
        });

        Schema::create('legacy_identity_maps', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('source_product', 24);
            $table->string('source_entity', 100);
            $table->string('source_id', 191);
            $table->string('canonical_entity', 100)->nullable();
            $table->string('canonical_id', 26)->nullable();
            $table->string('status', 24)->default('pending');
            $table->string('batch_key', 100)->nullable();
            $table->text('reconciliation_notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();
            $table->unique(['source_product', 'source_entity', 'source_id'], 'legacy_identity_maps_source_unique');
            $table->index(['canonical_entity', 'canonical_id']);
            $table->index(['status', 'batch_key']);
        });

        Schema::create('billing_customers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
            $table->string('provider', 40);
            $table->string('provider_account_key', 100)->default('default');
            $table->string('provider_customer_id', 191);
            $table->string('status', 24)->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_account_key', 'provider_customer_id'], 'billing_customers_provider_id_unique');
            $table->index(['workspace_id', 'status']);
        });

        Schema::create('product_subscriptions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignUlid('billing_customer_id')->nullable()->constrained('billing_customers')->nullOnDelete();
            $table->string('product', 24);
            $table->string('provider', 40);
            $table->string('provider_account_key', 100)->default('default');
            $table->string('provider_subscription_id', 191)->nullable();
            $table->string('provider_price_id', 191)->nullable();
            $table->string('plan_key', 100)->nullable();
            $table->string('status', 32)->default('pending');
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_starts_at')->nullable();
            $table->timestamp('current_period_ends_at')->nullable();
            $table->timestamp('cancel_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['provider', 'provider_account_key', 'provider_subscription_id'], 'product_subscriptions_provider_id_index');
            $table->index(['workspace_id', 'product', 'status']);
            $table->index(['product', 'current_period_ends_at']);
            $table->unique(['id', 'workspace_id', 'product'], 'product_subscriptions_scope_unique');
        });

        Schema::create('current_product_subscriptions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('product', 24);
            $table->ulid('product_subscription_id');
            $table->timestamps();
            $table->unique(['workspace_id', 'product']);
            $table->unique('product_subscription_id');
            $table->foreign(['product_subscription_id', 'workspace_id', 'product'], 'current_product_subscriptions_scope_fk')
                ->references(['id', 'workspace_id', 'product'])
                ->on('product_subscriptions')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('current_product_subscriptions');
        Schema::dropIfExists('product_subscriptions');
        Schema::dropIfExists('billing_customers');
        Schema::dropIfExists('legacy_identity_maps');
        Schema::dropIfExists('project_connections');
        Schema::dropIfExists('project_resources');
        Schema::dropIfExists('project_products');
        Schema::dropIfExists('project_environments');
        Schema::dropIfExists('project_memberships');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('workspace_product_access');
        Schema::dropIfExists('workspace_invitations');
        Schema::dropIfExists('workspace_memberships');
        Schema::dropIfExists('workspaces');
        Schema::dropIfExists('passkeys');
        Schema::dropIfExists('user_identities');
        Schema::dropIfExists('users');
    }
};
