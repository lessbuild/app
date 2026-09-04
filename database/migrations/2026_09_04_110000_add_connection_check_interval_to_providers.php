<?php

use App\Models\Provider;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table): void {
            $table->unsignedSmallInteger('connection_check_interval_minutes')
                ->default(Provider::DEFAULT_CONNECTION_CHECK_INTERVAL_MINUTES)
                ->after('connection_monitoring_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('providers', function (Blueprint $table): void {
            $table->dropColumn('connection_check_interval_minutes');
        });
    }
};
