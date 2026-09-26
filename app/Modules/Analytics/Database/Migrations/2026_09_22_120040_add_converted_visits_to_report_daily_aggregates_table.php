<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('analytics')->table('report_daily_aggregates', function (Blueprint $table): void {
            $table->unsignedInteger('converted_visits')->default(0)->after('conversions');
        });
    }

    public function down(): void
    {
        Schema::connection('analytics')->table('report_daily_aggregates', function (Blueprint $table): void {
            $table->dropColumn('converted_visits');
        });
    }
};
