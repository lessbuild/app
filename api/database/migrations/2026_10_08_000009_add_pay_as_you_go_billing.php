<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Pay-as-you-go usage beyond a tier's allowance: a spend cap on the usage selection, and how much overage Stripe has been told about each month. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_selections', function (Blueprint $table): void {
            $table->unsignedInteger('spend_cap_cents')->nullable();
        });
        Schema::create('usage_overage_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('account_id')->constrained()->cascadeOnDelete();
            $table->string('meter', 60);
            $table->date('period_start');
            $table->unsignedBigInteger('reported')->default(0);
            $table->timestamps();
            $table->unique(['account_id', 'meter', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_overage_reports');
        Schema::table('billing_selections', fn (Blueprint $table) => $table->dropColumn('spend_cap_cents'));
    }
};
