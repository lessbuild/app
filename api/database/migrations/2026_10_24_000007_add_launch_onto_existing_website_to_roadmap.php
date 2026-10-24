<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds "Launch onto a website you already have" to the public roadmap as shipped: Launch from a repository now picks
 * the repository from a list and lets each app go onto an existing website (with its domain) or a new one.
 */
return new class extends Migration
{
    /**
     * The roadmap item.
     *
     * @var string
     */
    public const TITLE = 'Launch onto a website you already have';

    /**
     * Add the item as shipped (and none in tests: roadmap entries are site content).
     *
     * @return void
     */
    public function up(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }
        DB::table('feature_requests')->updateOrInsert(['title' => self::TITLE], [
            'description' => 'Launch from a repository lets you pick the repository from a list and send each app to a website you’ve already added under Infrastructure (with its domain), instead of typing the repository address and a domain by hand.',
            'status' => 'shipped', 'votes_count' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Remove the item again.
     *
     * @return void
     */
    public function down(): void
    {
        DB::table('feature_requests')->where('title', self::TITLE)->delete();
    }
};
