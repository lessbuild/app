<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the table of people's devices that get push notifications (a browser or installed app each).
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint');
            $table->string('endpoint_hash', 64)->unique();
            $table->string('public_key', 200);
            $table->string('auth_secret', 100);
            $table->string('device', 120)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Drop the table.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
