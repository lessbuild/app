<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the assistant's conversations: one person's questions and the answers, kept for 30 days.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('assistant_conversations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->text('messages');
            $table->string('status', 12)->default('idle');
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['account_id', 'user_id', 'updated_at']);
        });
    }

    /**
     * Drop the conversations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('assistant_conversations');
    }
};
