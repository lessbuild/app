<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('analytics')->create('ingestion_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->uuid('batch_id');
            $table->unsignedInteger('event_count')->default(0);
            $table->string('status', 24)->default('pending');
            $table->timestamp('accepted_at');
            $table->timestamp('processed_at')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'batch_id']);
            $table->index(['status', 'accepted_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('analytics')->dropIfExists('ingestion_batches');
    }
};
