<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('analytics')->create('report_daily_aggregates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->date('local_date');
            $table->string('dimension', 32);
            $table->string('dimension_value', 2048)->nullable();
            $table->unsignedBigInteger('pageviews')->default(0);
            $table->unsignedBigInteger('visits')->default(0);
            $table->unsignedBigInteger('visitors')->default(0);
            $table->unsignedBigInteger('conversions')->default(0);
            $table->unsignedBigInteger('bounce_eligible')->default(0);
            $table->unsignedBigInteger('bounces')->default(0);
            $table->timestamps();
            $table->unique(['site_id', 'local_date', 'dimension', 'dimension_value'], 'report_daily_aggregate_unique');
            $table->index(['site_id', 'local_date', 'dimension']);
        });
    }

    public function down(): void
    {
        Schema::connection('analytics')->dropIfExists('report_daily_aggregates');
    }
};
