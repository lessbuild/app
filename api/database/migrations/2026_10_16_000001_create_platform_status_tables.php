<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keep BuildPusher's own status history: how often each part was checked and found not working each day, the
     * incidents opened and resolved from those checks, and the people who asked to be emailed about them.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('platform_status_days', function (Blueprint $table): void {
            $table->id();
            $table->date('day');
            $table->string('component', 40);
            $table->unsignedInteger('checks')->default(0);
            $table->unsignedInteger('failures')->default(0);
            $table->timestamps(6);
            $table->unique(['day', 'component']);
        });
        Schema::create('platform_status_incidents', function (Blueprint $table): void {
            $table->id();
            $table->string('component', 40);
            $table->string('name', 120);
            $table->timestamp('started_at', 6);
            $table->timestamp('resolved_at', 6)->nullable();
            $table->timestamps(6);
            $table->index(['component', 'resolved_at']);
            $table->index('started_at');
        });
        Schema::create('platform_status_subscribers', function (Blueprint $table): void {
            $table->id();
            $table->text('email');
            $table->string('email_hash', 64)->unique();
            $table->string('verification_token_hash', 64)->nullable();
            $table->text('unsubscribe_token');
            $table->timestamp('verified_at', 6)->nullable();
            $table->timestamps(6);
        });
    }

    /**
     * Forget the status history and its subscribers.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_status_subscribers');
        Schema::dropIfExists('platform_status_incidents');
        Schema::dropIfExists('platform_status_days');
    }
};
