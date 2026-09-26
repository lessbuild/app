<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('deployer')->create('alert_outbound_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('payload_id');
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('destination_key');
            $table->foreignId('alert_destination_id')->nullable()->constrained()->nullOnDelete();
            $table->string('destination_type', 20);
            $table->string('event', 16);
            $table->string('status', 16)->default('queued');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->unsignedTinyInteger('cycle_attempts')->default(0);
            $table->unsignedTinyInteger('manual_retry_count')->default(0);
            $table->unsignedInteger('generation')->default(0);
            $table->uuid('processing_token')->nullable();
            $table->timestamp('dispatched_at', 6)->nullable();
            $table->timestamp('next_attempt_at', 6)->nullable();
            $table->timestamp('retry_available_until', 6);
            $table->timestamp('accepted_at', 6)->nullable();
            $table->timestamp('failed_at', 6)->nullable();
            $table->string('error_code', 48)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->timestamps(6);
            $table->unique(['payload_id', 'destination_key'], 'alert_outbound_identity_unique');
            $table->index(['status', 'next_attempt_at', 'id'], 'alert_outbound_due_index');
            $table->index(['organization_id', 'created_at', 'id'], 'alert_outbound_workspace_index');
            $table->index(['alert_destination_id', 'created_at', 'id'], 'alert_outbound_destination_index');
        });

        // The retry body is encrypted and kept separately from visible delivery history.
        // It contains event data only: endpoint and signing secret are always reloaded from the destination.
        Schema::connection('deployer')->create('alert_outbound_delivery_payloads', function (Blueprint $table): void {
            $table->uuid('alert_outbound_delivery_id')->primary();
            $table->longText('payload');
            $table->timestamp('expires_at', 6);
            $table->timestamps(6);
            $table->foreign('alert_outbound_delivery_id', 'alert_outbound_payload_delivery_fk')
                ->references('id')->on('alert_outbound_deliveries')->cascadeOnDelete();
            $table->index(['expires_at', 'alert_outbound_delivery_id'], 'alert_outbound_payload_expiry_index');
        });

        Schema::connection('deployer')->create('alert_outbound_delivery_attempts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('alert_outbound_delivery_id');
            $table->unsignedInteger('number');
            $table->string('status', 16);
            $table->string('error_code', 48)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->timestamp('started_at', 6);
            $table->timestamp('finished_at', 6)->nullable();
            $table->foreign('alert_outbound_delivery_id', 'alert_outbound_attempt_delivery_fk')
                ->references('id')->on('alert_outbound_deliveries')->cascadeOnDelete();
            $table->unique(['alert_outbound_delivery_id', 'number'], 'alert_outbound_attempt_number_unique');
        });
    }

    public function down(): void
    {
        Schema::connection('deployer')->dropIfExists('alert_outbound_delivery_attempts');
        Schema::connection('deployer')->dropIfExists('alert_outbound_delivery_payloads');
        Schema::connection('deployer')->dropIfExists('alert_outbound_deliveries');
    }
};
