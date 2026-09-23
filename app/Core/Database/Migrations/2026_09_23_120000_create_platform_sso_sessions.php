<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'core';

    public function up(): void
    {
        Schema::create('platform_auth_sessions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('remember_token_hash', 64)->nullable()->index();
            $table->boolean('remembered')->default(false);
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();
            $table->index(['user_id', 'revoked_at']);
        });

        Schema::create('platform_sso_tickets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('token_hash', 64)->unique();
            $table->foreignUlid('auth_session_id')->constrained('platform_auth_sessions')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('issuer_origin', 255);
            $table->string('audience_origin', 255);
            $table->text('return_url');
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable()->index();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['auth_session_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_sso_tickets');
        Schema::dropIfExists('platform_auth_sessions');
    }
};
