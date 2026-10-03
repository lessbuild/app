<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Records each budget threshold an account crossed in a month, so owners hear about it once. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('infrastructure_budget_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('account_id')->constrained()->cascadeOnDelete();
            $table->char('period', 7);
            $table->unsignedTinyInteger('threshold');
            $table->decimal('monthly_cost', 12, 2);
            $table->timestamp('created_at')->nullable();
            $table->unique(['account_id', 'period', 'threshold']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('infrastructure_budget_alerts');
    }
};
