<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The total length of the day's visits, in seconds, so long-range reports can show the average visit duration. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analytics_daily_aggregates', function (Blueprint $table): void {
            $table->unsignedBigInteger('duration_seconds')->default(0)->after('bounces');
        });
    }

    public function down(): void
    {
        Schema::table('analytics_daily_aggregates', fn (Blueprint $table) => $table->dropColumn('duration_seconds'));
    }
};
