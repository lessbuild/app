<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var string */
    protected $connection = 'deployer';

    public function up(): void
    {
        Schema::connection('deployer')->create('deployment_succeeded_outbox_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('event_type', 100);
            $table->unsignedSmallInteger('event_version')->default(1);
            $table->unsignedBigInteger('source_build_id');
            $table->unsignedBigInteger('source_project_id');
            $table->unsignedBigInteger('source_environment_id');
            $table->json('payload');
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->string('last_error_code', 100)->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamps();
            $table->unique(['event_type', 'source_build_id'], 'deployment_outbox_source_unique');
            $table->index(['status', 'available_at'], 'deployment_outbox_due_index');
        });
    }

    public function down(): void
    {
        Schema::connection('deployer')->dropIfExists('deployment_succeeded_outbox_events');
    }
};
