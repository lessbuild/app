<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Marks the launching from a repository (stage 4 of docs/launch-plan.md) shipped on the roadmap. */
return new class extends Migration
{
    /**
     * Mark it shipped.
     *
     * @return void
     */
    public function up(): void
    {
        DB::table('feature_requests')->where('title', 'Launch from a repository')->update(['status' => 'shipped', 'shipped_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Put it back to planned.
     *
     * @return void
     */
    public function down(): void
    {
        DB::table('feature_requests')->where('title', 'Launch from a repository')->update(['status' => 'planned', 'shipped_at' => null, 'updated_at' => now()]);
    }
};
