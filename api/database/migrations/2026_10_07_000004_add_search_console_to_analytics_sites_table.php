<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A site's link to Google Search Console: the (encrypted) refresh token and the chosen property. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analytics_sites', function (Blueprint $table): void {
            $table->text('search_console_token')->nullable();
            $table->string('search_console_property')->nullable();
            $table->timestamp('search_console_connected_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('analytics_sites', fn (Blueprint $table) => $table->dropColumn(['search_console_token', 'search_console_property', 'search_console_connected_at']));
    }
};
