<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Puts editing environment variables on the roadmap, shipped with this deploy. */
return new class extends Migration
{
    /**
     * Add it (none in tests: roadmap entries are buildpusher.com's content).
     *
     * @return void
     */
    public function up(): void
    {
        if (app()->runningUnitTests() || DB::table('feature_requests')->where('title', 'Edit environment variables')->exists()) {
            return;
        }
        DB::table('feature_requests')->insert(['title' => 'Edit environment variables', 'description' => 'Change a variable’s value, scope, secrecy or rotation date in place, as a new version, without re-adding it.',
            'status' => 'shipped', 'votes_count' => 0, 'shipped_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Remove it again.
     *
     * @return void
     */
    public function down(): void
    {
        DB::table('feature_requests')->where('title', 'Edit environment variables')->delete();
    }
};
