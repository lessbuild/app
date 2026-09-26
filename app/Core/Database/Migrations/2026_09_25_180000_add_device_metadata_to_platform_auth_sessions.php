<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'core';

    public function up(): void
    {
        Schema::connection('core')->table('platform_auth_sessions', function (Blueprint $table): void {
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::connection('core')->table('platform_auth_sessions', function (Blueprint $table): void {
            $table->dropIndex(['last_seen_at']);
            $table->dropColumn(['ip_address', 'user_agent', 'last_seen_at']);
        });
    }
};
