<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('monitor')->create('alert_destinations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('type', 16);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('state_version')->default(0);
            $table->unsignedInteger('target_revision')->default(0);
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('endpoint_url')->nullable();
            $table->text('signing_secret')->nullable();
            $table->timestamps(6);
            $table->softDeletes('deleted_at', 6);
            $table->index(['workspace_id', 'created_at', 'id'], 'destinations_workspace_index');
        });

        Schema::connection('monitor')->create('alert_destination_alert_rule', function (Blueprint $table): void {
            $table->foreignId('alert_destination_id')->constrained()->cascadeOnDelete();
            $table->foreignId('alert_rule_id')->constrained()->cascadeOnDelete();
            $table->boolean('opened')->default(true);
            $table->boolean('recovered')->default(true);
            $table->primary(['alert_destination_id', 'alert_rule_id'], 'alert_routes_primary');
            $table->index(['alert_rule_id', 'alert_destination_id'], 'alert_routes_rule_index');
        });

        Schema::connection('monitor')->create('alert_deliveries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('alert_destination_id')->constrained()->cascadeOnDelete();
            $table->foreignId('incident_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 16);
            $table->unsignedInteger('target_revision');
            $table->text('payload');
            $table->string('status', 16)->default('queued');
            $table->unsignedInteger('generation')->default(0);
            $table->unsignedInteger('attempt_count')->default(0);
            $table->unsignedTinyInteger('cycle_attempts')->default(0);
            $table->uuid('queue_job_uuid')->nullable();
            $table->uuid('processing_token')->nullable();
            $table->timestamp('next_attempt_at', 6)->nullable();
            $table->timestamp('accepted_at', 6)->nullable();
            $table->timestamp('failed_at', 6)->nullable();
            $table->string('last_error_code', 48)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->timestamps(6);
            $table->unique(['incident_id', 'alert_destination_id', 'event'], 'alert_deliveries_event_unique');
            $table->index(['status', 'next_attempt_at', 'id'], 'alert_deliveries_due_index');
            $table->index(['alert_destination_id', 'created_at', 'id'], 'alert_deliveries_destination_index');
            $table->index(['workspace_id', 'created_at', 'id'], 'alert_deliveries_workspace_index');
        });

        Schema::connection('monitor')->create('alert_delivery_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('alert_delivery_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('status', 16);
            $table->string('error_code', 48)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->timestamp('started_at', 6);
            $table->timestamp('finished_at', 6)->nullable();
            $table->unique(['alert_delivery_id', 'number'], 'alert_attempt_number_unique');
        });
    }

    public function down(): void
    {
        Schema::connection('monitor')->dropIfExists('alert_delivery_attempts');
        Schema::connection('monitor')->dropIfExists('alert_deliveries');
        Schema::connection('monitor')->dropIfExists('alert_destination_alert_rule');
        Schema::connection('monitor')->dropIfExists('alert_destinations');
    }
};
