<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('monitor')->table('workspaces', function (Blueprint $table): void {
            $table->string('stripe_customer_id')->nullable()->unique();
            $table->string('stripe_subscription_id')->nullable()->unique();
            $table->string('stripe_price_id')->nullable();
            $table->string('billing_status')->default('inactive');
            $table->timestamp('billing_period_ends_at')->nullable();
            $table->boolean('billing_cancel_at_period_end')->default(false);
            $table->timestamp('billing_updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('monitor')->table('workspaces', function (Blueprint $table): void {
            $table->dropUnique('workspaces_stripe_customer_id_unique');
            $table->dropUnique('workspaces_stripe_subscription_id_unique');
            $table->dropColumn([
                'stripe_customer_id',
                'stripe_subscription_id',
                'stripe_price_id',
                'billing_status',
                'billing_period_ends_at',
                'billing_cancel_at_period_end',
                'billing_updated_at',
            ]);
        });
    }
};
