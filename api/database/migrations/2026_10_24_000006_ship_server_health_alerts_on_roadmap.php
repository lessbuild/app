<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Moves "Proactive server health alerts" (stage 1 of docs/platform-health-plan.md) to in-progress: built-in disk/memory alerts shipped; the stuck-queue signal and critical/reboot tiers remain. */
return new class extends Migration
{
    /**
     * Move it to in-progress.
     *
     * @return void
     */
    public function up(): void
    {
        DB::table('feature_requests')->where('title', 'Proactive server health alerts')->update(['status' => 'in_progress', 'updated_at' => now()]);
    }

    /**
     * Put it back to planned.
     *
     * @return void
     */
    public function down(): void
    {
        DB::table('feature_requests')->where('title', 'Proactive server health alerts')->update(['status' => 'planned', 'updated_at' => now()]);
    }
};
