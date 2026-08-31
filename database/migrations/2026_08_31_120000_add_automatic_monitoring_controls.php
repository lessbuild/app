<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table): void {
            $table->boolean('connection_monitoring_enabled')->default(true)->after('connection_checked_at');
        });

        Schema::table('websites', function (Blueprint $table): void {
            $table->boolean('health_monitoring_enabled')->default(true)->after('health_check_path');
        });
    }

    public function down(): void
    {
        Schema::table('providers', function (Blueprint $table): void {
            $table->dropColumn('connection_monitoring_enabled');
        });

        Schema::table('websites', function (Blueprint $table): void {
            $table->dropColumn('health_monitoring_enabled');
        });
    }
};
