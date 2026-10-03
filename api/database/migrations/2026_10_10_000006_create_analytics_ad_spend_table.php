<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the table of imported ad spend: what each campaign cost per day, from the ad platform's own export.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('analytics_ad_spend', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('analytics_sites')->cascadeOnDelete();
            $table->date('date');
            $table->string('source', 100);
            $table->string('campaign', 150);
            $table->unsignedBigInteger('cost_cents');
            $table->char('currency', 3);
            $table->unsignedBigInteger('clicks')->nullable();
            $table->unsignedBigInteger('impressions')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'date', 'source', 'campaign']);
        });
    }

    /**
     * Drop the table.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('analytics_ad_spend');
    }
};
