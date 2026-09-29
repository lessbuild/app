<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Status page updates posted to a subscriber's Slack channel or their own signed webhook. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_webhook_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('status_page_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->text('endpoint_url');
            $table->string('endpoint_hash', 64);
            $table->text('signing_secret')->nullable();
            $table->string('unsubscribe_token', 64);
            $table->timestamp('verified_at')->nullable();
            $table->unsignedSmallInteger('failure_count')->default(0);
            $table->timestamps();
            $table->unique(['status_page_id', 'endpoint_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_webhook_subscriptions');
    }
};
