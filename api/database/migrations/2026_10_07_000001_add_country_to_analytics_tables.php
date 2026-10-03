<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The visitor's country (ISO 3166 alpha-2), looked up from the IP address when the event arrives. The IP itself is
 * never stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analytics_events', function (Blueprint $table): void {
            $table->string('country_code', 2)->nullable()->after('operating_system');
        });
        Schema::table('analytics_visits', function (Blueprint $table): void {
            $table->string('country_code', 2)->nullable()->after('entry_utm_campaign');
        });
    }

    public function down(): void
    {
        Schema::table('analytics_visits', fn (Blueprint $table) => $table->dropColumn('country_code'));
        Schema::table('analytics_events', fn (Blueprint $table) => $table->dropColumn('country_code'));
    }
};
