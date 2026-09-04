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
            $table->unsignedTinyInteger('connection_failure_threshold')
                ->default(Provider::DEFAULT_CONNECTION_FAILURE_THRESHOLD)
                ->after('connection_check_interval_minutes');
            $table->unsignedSmallInteger('connection_failure_count')
                ->default(0)
                ->after('connection_failure_threshold');
        });
    }

    public function down(): void
    {
        Schema::table('providers', function (Blueprint $table): void {
            $table->dropColumn(['connection_failure_threshold', 'connection_failure_count']);
        });
    }
};
