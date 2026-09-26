<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // Null for personal events (password, passkeys…) that belong to a user rather than an account.
            $table->foreignUlid('account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUlid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            // Snapshot, so the log still reads correctly after the person is removed or renames themselves.
            $table->string('actor_name')->nullable();
            $table->string('actor_email')->nullable();
            $table->string('action', 64);
            $table->json('context')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('created_at');

            $table->index(['account_id', 'created_at']);
            $table->index(['actor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_entries');
    }
};
