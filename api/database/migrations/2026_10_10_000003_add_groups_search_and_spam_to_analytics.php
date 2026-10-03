<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Content groups and blocked referrers per site, and daily counts of the visits filtered out (bots, spam, ignored addresses). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analytics_sites', function (Blueprint $table): void {
            $table->json('content_groups')->nullable();
            $table->json('blocked_referrers')->nullable();
        });
        Schema::create('analytics_filtered_counts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('analytics_sites')->cascadeOnDelete();
            $table->date('date');
            $table->string('reason', 16);
            $table->unsignedBigInteger('count')->default(0);
            $table->timestamps();
            $table->unique(['site_id', 'date', 'reason']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_filtered_counts');
        Schema::table('analytics_sites', fn (Blueprint $table) => $table->dropColumn(['content_groups', 'blocked_referrers']));
    }
};
