<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'core';

    public function up(): void
    {
        Schema::connection('core')->create('analytics_checkout_attempts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // Keep the workspace ID after a workspace is removed so an event can be
            // rejected against its original binding and the attempt remains auditable.
            $table->string('core_workspace_id', 26);
            $table->string('analytics_workspace_id', 64);
            $table->string('initiated_by_user_id', 26);
            $table->string('provider_account_key', 100);
            $table->string('provider_customer_id', 191)->nullable();
            $table->string('provider_checkout_session_id', 191)->nullable();
            $table->string('provider_subscription_id', 191)->nullable();
            $table->string('plan_key', 100);
            $table->string('provider_price_id', 191);
            $table->json('plan_snapshot');
            $table->json('price_terms');
            $table->string('price_terms_hash', 64);
            $table->string('idempotency_key_hash', 64);
            $table->string('request_fingerprint', 64);
            $table->string('status', 32)->default('pending');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('failure_code', 100)->nullable();
            $table->timestamps();

            $table->unique(
                ['provider_account_key', 'core_workspace_id', 'idempotency_key_hash'],
                'analytics_checkout_attempts_idempotency_unique',
            );
            $table->unique(
                ['provider_account_key', 'provider_checkout_session_id'],
                'analytics_checkout_attempts_session_unique',
            );
            $table->unique(
                ['provider_account_key', 'provider_subscription_id'],
                'analytics_checkout_attempts_subscription_unique',
            );
            $table->index(
                ['core_workspace_id', 'status', 'expires_at'],
                'analytics_checkout_attempts_workspace_status_index',
            );
        });
    }

    public function down(): void
    {
        Schema::connection('core')->dropIfExists('analytics_checkout_attempts');
    }
};
