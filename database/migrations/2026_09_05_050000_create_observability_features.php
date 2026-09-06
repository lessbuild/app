<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->decimal('load_1m', 8, 2)->nullable();
            $table->decimal('load_5m', 8, 2)->nullable();
            $table->decimal('load_15m', 8, 2)->nullable();
            $table->unsignedTinyInteger('memory_percent')->nullable();
            $table->unsignedTinyInteger('disk_percent')->nullable();
            $table->unsignedBigInteger('uptime_seconds')->nullable();
            $table->timestamp('recorded_at');
            $table->index(['server_id', 'recorded_at']);
        });

        Schema::create('website_log_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('status', 20)->default('queued');
            $table->longText('log')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('refreshed_at')->nullable();
            $table->timestamps();
            $table->unique(['website_id', 'type']);
        });

        Schema::create('alert_destinations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->string('type', 20);
            $table->text('endpoint');
            $table->text('signing_secret');
            $table->json('events')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_delivered_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('status_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_published')->default(true);
            $table->timestamps();
        });

        Schema::create('status_page_website', function (Blueprint $table): void {
            $table->foreignId('status_page_id')->constrained()->cascadeOnDelete();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->string('display_name')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->primary(['status_page_id', 'website_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_page_website');
        Schema::dropIfExists('status_pages');
        Schema::dropIfExists('alert_destinations');
        Schema::dropIfExists('website_log_snapshots');
        Schema::dropIfExists('server_metrics');
    }
};
