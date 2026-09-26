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
        Schema::connection('monitor')->table('alert_rules', function (Blueprint $table) {
            $table->foreignId('metric_series_id')->nullable()->constrained('metric_series')->nullOnDelete();
            $table->double('numeric_threshold')->nullable();
            $table->string('aggregation', 16)->nullable();
            $table->string('comparison', 4)->nullable();
            $table->unsignedInteger('freshness_seconds')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('monitor')->table('alert_rules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('metric_series_id');
            $table->dropColumn(['numeric_threshold', 'aggregation', 'comparison', 'freshness_seconds']);
        });
    }
};
