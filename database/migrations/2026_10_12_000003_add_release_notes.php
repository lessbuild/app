<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the commits each deploy shipped, and a public release notes page per environment.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('builds', function (Blueprint $table): void {
            $table->json('release_commits')->nullable();
        });
        Schema::table('environments', function (Blueprint $table): void {
            $table->string('release_notes_token', 40)->nullable()->unique();
        });
    }

    /**
     * Remove them.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('builds', function (Blueprint $table): void {
            $table->dropColumn('release_commits');
        });
        Schema::table('environments', function (Blueprint $table): void {
            $table->dropUnique(['release_notes_token']);
            $table->dropColumn('release_notes_token');
        });
    }
};
