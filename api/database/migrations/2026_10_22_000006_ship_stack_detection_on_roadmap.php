<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Marks the stack detection (stage 3 of docs/launch-plan.md) shipped on the roadmap. */
return new class extends Migration
{
    /**
     * Mark it shipped.
     *
     * @return void
     */
    public function up(): void
    {
        DB::table('feature_requests')->where('title', 'Stack detection')->update(['status' => 'shipped', 'shipped_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Put it back to planned.
     *
     * @return void
     */
    public function down(): void
    {
        DB::table('feature_requests')->where('title', 'Stack detection')->update(['status' => 'planned', 'shipped_at' => null, 'updated_at' => now()]);
    }
};
