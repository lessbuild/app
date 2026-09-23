<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('monitor')->create('issue_digest_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->string('recipient_email', 254);
            $table->timestamp('period_start', 6);
            $table->timestamp('period_end', 6);
            $table->string('status', 16)->default('sending');
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('new_count')->default(0);
            $table->unsignedInteger('resolved_count')->default(0);
            $table->unsignedInteger('open_count')->default(0);
            $table->unsignedInteger('critical_open_count')->default(0);
            $table->string('last_error_code', 64)->nullable();
            $table->timestamp('sending_started_at', 6)->nullable();
            $table->timestamp('sent_at', 6)->nullable();
            $table->timestamp('failed_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['workspace_id', 'recipient_id', 'period_start', 'period_end'], 'issue_digest_delivery_period_unique');
            $table->index(['workspace_id', 'status', 'created_at', 'id'], 'issue_digest_deliveries_workspace_status_index');
            $table->index(['recipient_id', 'created_at', 'id'], 'issue_digest_deliveries_recipient_index');
        });
    }

    public function down(): void
    {
        Schema::connection('monitor')->dropIfExists('issue_digest_deliveries');
    }
};
