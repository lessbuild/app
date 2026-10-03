<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the account's webhook endpoints, which receive signed events (deploys, incidents, servers, billing and
     * more), and the log of each delivery.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('url', 2048);
            $table->string('description', 200)->nullable();
            $table->json('events');
            $table->text('signing_secret');
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('failure_count')->default(0);
            $table->string('last_error', 300)->nullable();
            $table->timestamp('last_delivered_at')->nullable();
            $table->timestamps();
        });
        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('webhook_endpoint_id')->constrained()->cascadeOnDelete();
            $table->string('event', 60);
            $table->json('payload');
            $table->string('status', 12)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->string('error', 300)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->index(['webhook_endpoint_id', 'created_at']);
        });
    }

    /**
     * Drop them.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
    }
};
