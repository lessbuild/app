<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table): void {
            $table->text('webhook_secret')->nullable()->after('branch');
            $table->boolean('webhook_enabled')->default(false)->after('webhook_secret');
            $table->boolean('webhook_pending')->default(false)->after('webhook_enabled');
            $table->timestamp('webhook_last_received_at')->nullable()->after('webhook_pending');
        });

        Schema::create('repository_webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->string('delivery_id');
            $table->string('status');
            $table->timestamps();
            $table->unique(['repository_id', 'delivery_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repository_webhook_deliveries');

        Schema::table('repositories', function (Blueprint $table): void {
            $table->dropColumn([
                'webhook_secret',
                'webhook_enabled',
                'webhook_pending',
                'webhook_last_received_at',
            ]);
        });
    }
};
