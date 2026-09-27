<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Infrastructure part 7: each server's monthly cost (from its provider's catalog, or entered for imported servers) and an account budget. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->decimal('monthly_cost', 10, 2)->nullable()->after('size');
            $table->string('monthly_cost_currency', 3)->nullable()->after('monthly_cost');
            $table->string('monthly_cost_source', 10)->nullable()->after('monthly_cost_currency');
            $table->timestamp('monthly_cost_checked_at', 6)->nullable()->after('monthly_cost_source');
        });
        Schema::table('accounts', function (Blueprint $table): void {
            $table->decimal('monthly_infrastructure_budget', 10, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('accounts', fn (Blueprint $table) => $table->dropColumn('monthly_infrastructure_budget'));
        Schema::table('servers', fn (Blueprint $table) => $table->dropColumn(['monthly_cost', 'monthly_cost_currency', 'monthly_cost_source', 'monthly_cost_checked_at']));
    }
};
