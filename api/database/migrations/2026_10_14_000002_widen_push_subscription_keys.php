<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Push subscription keys are stored encrypted, and the ciphertext is longer than the keys: keep them in text columns. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table): void {
            $table->text('public_key')->change();
            $table->text('auth_secret')->change();
        });
    }

    public function down(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table): void {
            $table->string('public_key', 200)->change();
            $table->string('auth_secret', 100)->change();
        });
    }
};
