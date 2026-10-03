<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * More about each visit: UTM term and content, the marketing channel, screen size, browser and system versions, and
 * region and city (when the city edition of the GeoIP database is installed), and the custom event properties a site
 * has chosen to keep.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analytics_sites', function (Blueprint $table): void {
            $table->json('custom_properties')->nullable();
        });
        Schema::table('analytics_events', function (Blueprint $table): void {
            $table->string('utm_term', 150)->nullable();
            $table->string('utm_content', 150)->nullable();
            $table->string('channel', 32)->nullable();
            $table->string('screen_size', 16)->nullable();
            $table->string('browser_version', 32)->nullable();
            $table->string('os_version', 32)->nullable();
            $table->string('region', 100)->nullable();
            $table->string('city', 100)->nullable();
        });
        Schema::table('analytics_visits', function (Blueprint $table): void {
            $table->string('entry_utm_term', 150)->nullable();
            $table->string('entry_utm_content', 150)->nullable();
            $table->string('entry_channel', 32)->nullable();
            $table->string('region', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->index(['site_id', 'entry_channel', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::table('analytics_sites', fn (Blueprint $table) => $table->dropColumn('custom_properties'));
        Schema::table('analytics_visits', function (Blueprint $table): void {
            $table->dropIndex(['site_id', 'entry_channel', 'started_at']);
            $table->dropColumn(['entry_utm_term', 'entry_utm_content', 'entry_channel', 'region', 'city']);
        });
        Schema::table('analytics_events', fn (Blueprint $table) => $table->dropColumn(['utm_term', 'utm_content', 'channel', 'screen_size', 'browser_version', 'os_version', 'region', 'city']));
    }
};
