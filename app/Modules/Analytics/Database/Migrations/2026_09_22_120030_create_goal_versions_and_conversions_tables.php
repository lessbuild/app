<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('analytics')->create('goal_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('goal_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 24);
            $table->string('match_type', 24)->default('exact');
            $table->string('match_value', 255);
            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();
            $table->timestamps();
            $table->index(['goal_id', 'effective_from', 'effective_to']);
        });

        Schema::connection('analytics')->create('goal_conversions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('goal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('goal_version_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('analytics_event_id')->constrained('analytics_events')->cascadeOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('converted_at');
            $table->timestamps();
            $table->unique(['goal_id', 'analytics_event_id']);
            $table->index(['site_id', 'converted_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('analytics')->dropIfExists('goal_conversions');
        Schema::connection('analytics')->dropIfExists('goal_versions');
    }
};
