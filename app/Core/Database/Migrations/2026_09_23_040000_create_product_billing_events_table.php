<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'core';

    public function up(): void
    {
        Schema::connection('core')->create('product_billing_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product', 24);
            $table->string('provider', 40);
            $table->string('provider_account_key', 100)->default('default');
            $table->string('provider_event_id', 191);
            $table->string('event_type', 191);
            $table->unsignedBigInteger('provider_created_at')->nullable();
            $table->string('processing_status', 32)->default('applied');
            $table->string('ignored_reason', 64)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(
                ['provider', 'provider_account_key', 'provider_event_id'],
                'product_billing_events_provider_event_unique',
            );
            $table->index(['workspace_id', 'product', 'provider_created_at'], 'product_billing_events_workspace_product_time_index');
        });
    }

    public function down(): void
    {
        Schema::connection('core')->dropIfExists('product_billing_events');
    }
};
