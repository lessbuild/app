<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('monitor')->table('ingest_receipts', function (Blueprint $table) {
            $table->unsignedInteger('processing_attempts')->default(0);
            $table->unsignedInteger('generation')->default(1);
            $table->unsignedInteger('recovery_count')->default(0);
            $table->unsignedBigInteger('queue_job_id')->nullable();
            $table->uuid('processing_token')->nullable();
            $table->timestamp('processing_started_at', 6)->nullable();
            $table->timestamp('next_attempt_at', 6)->nullable();
            $table->timestamp('failed_at', 6)->nullable();
            $table->string('last_error_code', 40)->nullable();
            $table->index(['status', 'next_attempt_at', 'id'], 'receipts_recovery_index');
        });

        Schema::connection('monitor')->create('ingest_payloads', function (Blueprint $table) {
            $table->foreignUlid('ingest_receipt_id')->primary()->constrained()->cascadeOnDelete();
            $table->longText('payload');
            $table->timestamps(6);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('monitor')->dropIfExists('ingest_payloads');

        Schema::connection('monitor')->table('ingest_receipts', function (Blueprint $table) {
            $table->dropIndex('receipts_recovery_index');
            $table->dropColumn([
                'processing_attempts', 'generation', 'recovery_count', 'queue_job_id',
                'processing_token', 'processing_started_at', 'next_attempt_at', 'failed_at', 'last_error_code',
            ]);
        });
    }
};
