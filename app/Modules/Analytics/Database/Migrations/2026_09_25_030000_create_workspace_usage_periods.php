<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('analytics')->create('workspace_usage_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->unsignedBigInteger('accepted_events')->default(0);
            $table->timestamps();
            $table->unique(['workspace_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::connection('analytics')->dropIfExists('workspace_usage_periods');
    }
};
