<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var string */
    protected $connection = 'monitor';

    public function up(): void
    {
        Schema::connection('monitor')->create('project_connection_event_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('delivery_id', 26);
            $table->string('project_connection_id', 26);
            $table->string('handler', 100);
            $table->char('payload_hash', 64);
            $table->foreignId('deployment_id')->constrained('deployments')->restrictOnDelete();
            $table->timestamp('processed_at', 6);
            $table->timestamps(6);
            $table->unique(['delivery_id', 'handler'], 'connection_receipt_delivery_handler_unique');
            $table->index(['project_connection_id', 'processed_at'], 'connection_receipt_connection_time_index');
        });
    }

    public function down(): void
    {
        Schema::connection('monitor')->dropIfExists('project_connection_event_receipts');
    }
};
