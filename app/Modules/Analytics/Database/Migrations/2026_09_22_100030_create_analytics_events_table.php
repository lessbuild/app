<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('analytics')->create('analytics_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingestion_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('event_id');
            $table->string('type', 32);
            $table->timestamp('occurred_at');
            $table->timestamp('received_at');
            $table->string('path', 2048);
            $table->string('referrer_host', 255)->nullable();
            $table->string('utm_source', 100)->nullable();
            $table->string('utm_medium', 100)->nullable();
            $table->string('utm_campaign', 150)->nullable();
            $table->string('device_category', 32)->nullable();
            $table->string('browser', 64)->nullable();
            $table->string('operating_system', 64)->nullable();
            $table->string('visitor_hash', 64)->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'event_id']);
            $table->index(['site_id', 'occurred_at']);
            $table->index(['site_id', 'type', 'occurred_at']);
            $table->index(['site_id', 'path']);
            $table->index(['site_id', 'visitor_hash', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('analytics')->dropIfExists('analytics_events');
    }
};
