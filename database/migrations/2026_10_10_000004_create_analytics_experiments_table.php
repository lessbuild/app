<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A/B tests: a site's experiments, their variants and the goal that decides the winner. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_experiments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('analytics_sites')->cascadeOnDelete();
            $table->string('key', 60);
            $table->string('name', 120);
            $table->json('variants');
            $table->foreignId('goal_id')->nullable()->constrained('analytics_goals')->nullOnDelete();
            $table->string('status', 16)->default('running');
            $table->timestamp('started_at');
            $table->timestamp('stopped_at')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_experiments');
    }
};
