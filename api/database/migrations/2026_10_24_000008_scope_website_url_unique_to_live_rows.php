<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes a website's url unique only among websites that aren't deleted, so a deleted website's url can be used again.
 * The plain unique index counted soft-deleted rows, so after deleting a website its domain stayed reserved for good.
 * SQLite and PostgreSQL (what Buildpusher runs on) both support a partial unique index.
 */
return new class extends Migration
{
    /**
     * Replace the plain unique index on websites.url with one that ignores soft-deleted rows.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('websites', function (Illuminate\Database\Schema\Blueprint $table): void {
            $table->dropUnique(['url']);
        });
        DB::statement('CREATE UNIQUE INDEX websites_url_active_unique ON websites (url) WHERE deleted_at IS NULL');
    }

    /**
     * Put the plain unique index back.
     *
     * @return void
     */
    public function down(): void
    {
        DB::statement('DROP INDEX websites_url_active_unique');
        Schema::table('websites', function (Illuminate\Database\Schema\Blueprint $table): void {
            $table->unique('url');
        });
    }
};
