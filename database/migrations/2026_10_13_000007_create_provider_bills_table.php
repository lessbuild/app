<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the table of what cloud providers actually charged: each month's invoice, and this month so far.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('provider_bills', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->string('period', 7);
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('USD');
            $table->boolean('final')->default(false);
            $table->timestamps();
            $table->unique(['provider_id', 'period']);
        });
        Schema::table('providers', function (Blueprint $table): void {
            $table->string('billing_error', 300)->nullable();
            $table->timestamp('billing_checked_at')->nullable();
        });
    }

    /**
     * Drop them.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('providers', function (Blueprint $table): void {
            $table->dropColumn(['billing_error', 'billing_checked_at']);
        });
        Schema::dropIfExists('provider_bills');
    }
};
