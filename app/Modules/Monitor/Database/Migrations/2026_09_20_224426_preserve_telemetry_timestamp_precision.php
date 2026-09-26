<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('monitor')->table('telemetry_events', function (Blueprint $table): void {
            $table->dateTime('occurred_at', 6)->change();
            $table->decimal('duration_ms', 20, 6)->nullable()->change();
            $table->string('timestamp_unix_nano', 20)->nullable();
            $table->string('end_timestamp_unix_nano', 20)->nullable();
        });
    }

    /**
     * Remove exact timestamps while retaining widened columns to avoid truncating existing values.
     */
    public function down(): void
    {
        Schema::connection('monitor')->table('telemetry_events', function (Blueprint $table): void {
            $table->dropColumn(['timestamp_unix_nano', 'end_timestamp_unix_nano']);
        });
    }
};
