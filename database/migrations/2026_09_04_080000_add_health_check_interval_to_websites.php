<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->unsignedSmallInteger('health_check_interval_minutes')
                ->default(5)
                ->after('health_monitoring_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->dropColumn('health_check_interval_minutes');
        });
    }
};
