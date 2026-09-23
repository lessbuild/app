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
        Schema::connection('monitor')->create('maintenance_windows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->text('reason')->nullable();
            $table->timestamp('starts_at', 6);
            $table->timestamp('ends_at', 6);
            $table->timestamps(6);
            $table->index(['workspace_id', 'starts_at', 'ends_at', 'id'], 'maintenance_windows_workspace_time_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('monitor')->dropIfExists('maintenance_windows');
    }
};
