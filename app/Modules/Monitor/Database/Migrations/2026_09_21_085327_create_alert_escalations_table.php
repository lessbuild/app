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
        Schema::connection('monitor')->create('alert_escalations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('alert_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('alert_destination_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('delay_minutes');
            $table->unsignedTinyInteger('position');
            $table->boolean('enabled')->default(true);
            $table->timestamps(6);
            $table->unique(['alert_rule_id', 'alert_destination_id'], 'alert_escalations_rule_destination_unique');
            $table->unique(['alert_rule_id', 'position'], 'alert_escalations_rule_position_unique');
            $table->index(['alert_destination_id', 'enabled', 'delay_minutes'], 'alert_escalations_destination_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('monitor')->dropIfExists('alert_escalations');
    }
};
