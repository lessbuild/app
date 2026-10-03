<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public status pages (merging Monitor's and Deployer's): an account's pages show chosen monitors,
 * status updates written by the team, and email subscriptions to those updates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->string('slug', 100)->unique();
            $table->text('description')->nullable();
            $table->boolean('published')->default(false);
            $table->timestamps(6);
            $table->index(['account_id', 'name', 'id'], 'status_pages_account_name_index');
        });

        Schema::create('status_page_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('status_page_id')->constrained()->cascadeOnDelete();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->string('label', 120);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps(6);
            $table->unique(['status_page_id', 'monitor_id'], 'status_page_components_monitor_unique');
            $table->index(['status_page_id', 'position', 'id'], 'status_page_components_order_index');
        });

        Schema::create('status_updates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('status_page_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 20);
            $table->string('status', 30);
            $table->string('severity', 20);
            $table->string('title', 255);
            $table->text('message');
            $table->text('root_cause')->nullable();
            $table->text('remediation')->nullable();
            $table->text('follow_up')->nullable();
            $table->timestamp('starts_at', 6);
            $table->timestamp('ends_at', 6)->nullable();
            $table->timestamp('resolved_at', 6)->nullable();
            $table->timestamps(6);
            $table->index(['status_page_id', 'starts_at', 'id'], 'status_updates_page_start_index');
        });

        Schema::create('status_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('status_page_id')->constrained()->cascadeOnDelete();
            $table->text('email');
            $table->char('email_hash', 64);
            $table->char('verification_token_hash', 64)->nullable();
            $table->text('unsubscribe_token');
            $table->timestamp('verified_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['status_page_id', 'email_hash'], 'status_subscriptions_email_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_subscriptions');
        Schema::dropIfExists('status_updates');
        Schema::dropIfExists('status_page_components');
        Schema::dropIfExists('status_pages');
    }
};
