<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'core';

    public function up(): void
    {
        Schema::connection('core')->table('users', function (Blueprint $table): void {
            $table->boolean('is_platform_admin')->default(false);
            $table->timestamp('platform_admin_granted_at')->nullable();
        });

        // Grant/revoke history is append-only and keeps plain user IDs so it survives account deletion.
        Schema::connection('core')->create('platform_admin_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('user_id', 26)->index();
            $table->string('actor_user_id', 26)->nullable();
            $table->string('action', 16);
            $table->string('source', 32);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::connection('core')->dropIfExists('platform_admin_events');
        Schema::connection('core')->table('users', function (Blueprint $table): void {
            $table->dropColumn(['is_platform_admin', 'platform_admin_granted_at']);
        });
    }
};
