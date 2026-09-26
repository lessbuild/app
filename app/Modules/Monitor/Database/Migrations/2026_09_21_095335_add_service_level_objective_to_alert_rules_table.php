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
        Schema::connection('monitor')->table('alert_rules', function (Blueprint $table): void {
            $table->foreignId('service_level_objective_id')->nullable()->after('environment_id')
                ->constrained('service_level_objectives')->nullOnDelete();
            $table->index(['service_level_objective_id', 'enabled'], 'alerts_slo_enabled_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('monitor')->table('alert_rules', function (Blueprint $table): void {
            $table->dropIndex('alerts_slo_enabled_index');
            $table->dropConstrainedForeignId('service_level_objective_id');
        });
    }
};
