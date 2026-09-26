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
        Schema::connection('monitor')->create('dashboard_widgets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dashboard_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->unsignedSmallInteger('position');
            $table->json('configuration')->nullable();
            $table->timestamps(6);
            $table->unique(['dashboard_id', 'position'], 'dashboard_widgets_position_unique');
            $table->index(['dashboard_id', 'type', 'position'], 'dashboard_widgets_type_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('monitor')->dropIfExists('dashboard_widgets');
    }
};
