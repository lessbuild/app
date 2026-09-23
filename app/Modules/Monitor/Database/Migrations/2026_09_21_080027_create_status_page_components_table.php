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
        Schema::connection('monitor')->create('status_page_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('status_page_id')->constrained()->cascadeOnDelete();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->string('label', 120);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps(6);
            $table->unique(['status_page_id', 'monitor_id'], 'status_page_component_monitor_unique');
            $table->index(['status_page_id', 'position', 'id'], 'status_page_components_order_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('monitor')->dropIfExists('status_page_components');
    }
};
