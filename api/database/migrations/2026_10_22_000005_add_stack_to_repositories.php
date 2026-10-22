<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Keeps what was found in each repository (its apps and what they need), when, and why it couldn't be read. */
return new class extends Migration
{
    /**
     * Add the columns.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table): void {
            $table->json('stack')->nullable();
            $table->timestamp('stack_checked_at')->nullable();
            $table->string('stack_error', 500)->nullable();
        });
    }

    /**
     * Remove them again.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('repositories', function (Blueprint $table): void {
            $table->dropColumn(['stack', 'stack_checked_at', 'stack_error']);
        });
    }
};
