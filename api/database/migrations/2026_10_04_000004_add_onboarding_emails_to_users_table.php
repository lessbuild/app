<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Getting-started emails: whether someone wants them, and whether the one reminder has been sent. */
return new class extends Migration
{
    /**
     * Add the preference and the reminder time.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('getting_started_emails')->default(true);
            $table->timestamp('onboarding_nudged_at')->nullable();
        });
    }

    /**
     * Drop the preference and the reminder time.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['getting_started_emails', 'onboarding_nudged_at']);
        });
    }
};
