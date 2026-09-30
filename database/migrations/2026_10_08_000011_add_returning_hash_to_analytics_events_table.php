<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A stable, per-site hash of a returning visitor's browser ID, for retention reports on sites whose snippet opts in. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analytics_events', function (Blueprint $table): void {
            $table->string('returning_hash', 64)->nullable();
            $table->index(['site_id', 'returning_hash', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::table('analytics_events', function (Blueprint $table): void {
            $table->dropIndex(['site_id', 'returning_hash', 'occurred_at']);
            $table->dropColumn('returning_hash');
        });
    }
};
