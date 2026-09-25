<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('monitor')->create('product_deletion_fences', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 16);
            $table->string('source_id', 64);
            $table->string('request_id', 26);
            $table->string('step_id', 26);
            $table->char('payload_hash', 64);
            $table->json('target');
            $table->string('status', 16)->default('prepared');
            $table->timestamp('prepared_at', 6);
            $table->timestamp('completed_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['kind', 'source_id'], 'product_deletion_fences_source_unique');
            $table->index(['request_id', 'status'], 'product_deletion_fences_request_status_index');
        });

        Schema::connection('monitor')->create('product_deletion_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 16);
            $table->string('source_id', 64);
            $table->string('request_id', 26);
            $table->string('step_id', 26);
            $table->char('payload_hash', 64);
            $table->timestamp('completed_at', 6);
            $table->unique(['kind', 'source_id'], 'product_deletion_receipts_source_unique');
            $table->index(['request_id', 'step_id'], 'product_deletion_receipts_request_step_index');
        });
    }

    public function down(): void
    {
        Schema::connection('monitor')->dropIfExists('product_deletion_receipts');
        Schema::connection('monitor')->dropIfExists('product_deletion_fences');
    }
};
