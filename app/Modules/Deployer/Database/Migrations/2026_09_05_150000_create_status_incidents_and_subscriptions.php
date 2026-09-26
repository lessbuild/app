<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_incidents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('status_page_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 20)->default('incident');
            $table->string('status', 30);
            $table->string('severity', 20)->default('minor');
            $table->string('title');
            $table->text('message');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['status_page_id', 'starts_at']);
        });

        Schema::create('status_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('status_page_id')->constrained()->cascadeOnDelete();
            $table->text('email');
            $table->char('email_hash', 64);
            $table->char('verification_token_hash', 64)->nullable();
            $table->text('unsubscribe_token');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['status_page_id', 'email_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_subscriptions');
        Schema::dropIfExists('status_incidents');
    }
};
