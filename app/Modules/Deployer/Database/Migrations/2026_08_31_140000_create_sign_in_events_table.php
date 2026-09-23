<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sign_in_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('method', 20);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('signed_in_at');

            $table->index(['user_id', 'signed_in_at']);
            $table->index('signed_in_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sign_in_events');
    }
};
