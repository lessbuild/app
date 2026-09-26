<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('monitor')->create('usage_alert_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->string('recipient_email', 254);
            $table->timestamp('period_start', 6);
            $table->timestamp('period_end', 6);
            $table->unsignedTinyInteger('threshold');
            $table->string('status', 16)->default('sending');
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedBigInteger('event_count')->default(0);
            $table->unsignedBigInteger('event_limit')->default(0);
            $table->unsignedTinyInteger('percentage')->default(0);
            $table->string('last_error_code', 64)->nullable();
            $table->timestamp('sending_started_at', 6)->nullable();
            $table->timestamp('sent_at', 6)->nullable();
            $table->timestamp('failed_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['workspace_id', 'recipient_id', 'period_start', 'threshold'], 'usage_alert_delivery_period_unique');
            $table->index(['workspace_id', 'status', 'created_at', 'id'], 'usage_alert_deliveries_workspace_status_index');
        });
    }

    public function down(): void
    {
        Schema::connection('monitor')->dropIfExists('usage_alert_deliveries');
    }
};
