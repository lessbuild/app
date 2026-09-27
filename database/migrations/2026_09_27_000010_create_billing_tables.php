<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The account's Stripe customer and its single subscription.
        Schema::create('billing_accounts', function (Blueprint $table): void {
            $table->foreignUlid('account_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('stripe_customer_id')->nullable()->unique();
            $table->string('stripe_subscription_id')->nullable()->unique();
            $table->string('status', 24)->default('none');
            $table->timestamp('current_period_end')->nullable();
            $table->timestamps();
        });

        // What the account chose: one tier per service and any add-ons. The source of truth for entitlements.
        Schema::create('billing_selections', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('account_id')->constrained()->cascadeOnDelete();
            $table->string('service', 32);
            $table->string('kind', 8);
            $table->string('item_key', 40);
            $table->unsignedInteger('quantity')->default(1);
            $table->string('stripe_item_id')->nullable();
            // A grandfathered price from the old apps, used instead of the catalogue's price.
            $table->string('legacy_price_id')->nullable();
            // Set when the selection has been cancelled and lasts until the end of the paid period.
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->unique(['account_id', 'service', 'kind', 'item_key']);
        });

        Schema::create('usage_records', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('account_id')->constrained()->cascadeOnDelete();
            $table->string('meter', 48);
            $table->timestamp('period_start');
            $table->unsignedBigInteger('quantity')->default(0);
            $table->unsignedBigInteger('reported_quantity')->default(0);
            $table->timestamps();
            $table->unique(['account_id', 'meter', 'period_start']);
        });

        // Stripe webhook deliveries already handled, so retries are processed once.
        Schema::create('billing_webhook_events', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('type', 80);
            $table->timestamp('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_webhook_events');
        Schema::dropIfExists('usage_records');
        Schema::dropIfExists('billing_selections');
        Schema::dropIfExists('billing_accounts');
    }
};
