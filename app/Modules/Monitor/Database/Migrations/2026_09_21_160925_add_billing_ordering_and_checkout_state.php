<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('monitor')->table('workspaces', function (Blueprint $table): void {
            $table->string('stripe_checkout_session_id')->nullable()->unique();
            $table->text('stripe_checkout_url')->nullable();
            $table->string('billing_checkout_plan', 32)->nullable();
            $table->timestamp('billing_checkout_started_at', 6)->nullable();
            $table->unsignedBigInteger('billing_event_created_at')->nullable();
            $table->string('billing_event_id')->nullable();
            $table->index(['billing_event_created_at', 'billing_event_id'], 'workspaces_billing_event_position_index');
        });

        Schema::connection('monitor')->table('billing_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('stripe_created_at')->nullable();
            $table->string('processing_status', 16)->default('applied');
            $table->string('ignored_reason', 64)->nullable();
            $table->index(['workspace_id', 'stripe_created_at', 'id'], 'billing_events_workspace_position_index');
        });
    }

    public function down(): void
    {
        Schema::connection('monitor')->table('workspaces', function (Blueprint $table): void {
            $table->dropUnique('workspaces_stripe_checkout_session_id_unique');
            $table->dropIndex('workspaces_billing_event_position_index');
            $table->dropColumn([
                'stripe_checkout_session_id',
                'stripe_checkout_url',
                'billing_checkout_plan',
                'billing_checkout_started_at',
                'billing_event_created_at',
                'billing_event_id',
            ]);
        });

        Schema::connection('monitor')->table('billing_events', function (Blueprint $table): void {
            $table->dropIndex('billing_events_workspace_position_index');
            $table->dropColumn(['stripe_created_at', 'processing_status', 'ignored_reason']);
        });
    }
};
