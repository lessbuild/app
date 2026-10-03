<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add browser error tracking to environments: a public key for the page script, and the origins it may send from.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('environments', function (Blueprint $table): void {
            $table->string('browser_key', 40)->nullable()->unique();
            $table->json('browser_origins')->nullable();
        });
    }

    /**
     * Remove browser error tracking.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('environments', function (Blueprint $table): void {
            $table->dropUnique(['browser_key']);
            $table->dropColumn(['browser_key', 'browser_origins']);
        });
    }
};
