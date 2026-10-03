<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Addresses and networks whose visits a site never counts, such as the team's office. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analytics_sites', function (Blueprint $table): void {
            $table->json('excluded_ips')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('analytics_sites', fn (Blueprint $table) => $table->dropColumn('excluded_ips'));
    }
};
