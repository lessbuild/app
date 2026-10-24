<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the governance, tooling, incident and DR stages of the platform health plan (docs/platform-health-plan.md,
 * stages 18-21) to the public roadmap as planned. Each stage's own migration marks it shipped.
 */
return new class extends Migration
{
    /**
     * The roadmap items, by title.
     *
     * @var array<string, string>
     */
    public const ITEMS = [
        'Access and governance' => 'Time-boxed break-glass access to protected environments, the option to enforce SSO and 2FA across an account, and recurring access reviews.',
        'CLI and CI integration' => 'A Buildpusher CLI and a GitHub Action over the API, plus generating a local .env and devcontainer from an environment.',
        'AI-assisted incidents' => 'When an alert fires, Buildpusher summarises the likely cause from recent deploys and errors and drafts the postmortem.',
        'Portability and disaster recovery' => 'Stand up a warm copy of a website in another region or provider and fail over to it.',
    ];

    /**
     * Add the items, leaving any that already exist alone (and none in tests: roadmap entries are site content).
     *
     * @return void
     */
    public function up(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }
        foreach (self::ITEMS as $title => $description) {
            if (! DB::table('feature_requests')->where('title', $title)->exists()) {
                DB::table('feature_requests')->insert(['title' => $title, 'description' => $description, 'status' => 'planned', 'votes_count' => 0, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    /**
     * Remove the items again.
     *
     * @return void
     */
    public function down(): void
    {
        DB::table('feature_requests')->whereIn('title', array_keys(self::ITEMS))->delete();
    }
};
