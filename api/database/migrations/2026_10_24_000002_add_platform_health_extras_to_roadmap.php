<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the later stages of the platform health & resilience plan (docs/platform-health-plan.md, stages 7-17) to the
 * public roadmap as planned. Each stage's own migration marks it shipped.
 */
return new class extends Migration
{
    /**
     * The roadmap items, by title.
     *
     * @var array<string, string>
     */
    public const ITEMS = [
        'TLS and DNS drift alerts' => 'Buildpusher warns before a website’s certificate expires and when its domain’s DNS stops pointing at the server.',
        'Dependency-update pull requests' => 'For a vulnerable package in a deployed app, Buildpusher opens a pull request bumping it to the fixed version.',
        'Uptime and latency budgets' => 'A monthly reliability summary per environment, showing what spent the error budget and the incidents behind it.',
        'Deploy impact preview' => 'Before a deploy, see what it will touch: pending migrations, changed variables, new or removed workers, and the files changed.',
        'Recovery drills' => 'Prove recovery in one action across database restore, shared files and replica failover, and see the last result.',
        'Audit log export' => 'Stream the account audit log to a webhook or SIEM and export it as CSV for compliance.',
        'Cost control' => 'Alerts when a bill jumps, and suggestions to hibernate idle environments or right-size servers, each with the saving shown.',
        'Secret rotation reminders' => 'Buildpusher reminds you when a secret is due to rotate and guides the rotation.',
        'Environment-wide rollback' => 'Roll a whole environment back to a known-good set of releases in one action, not one repository at a time.',
        'Provisioning resumability' => 'Resume a server setup that stopped half-way from where it stopped, instead of starting over.',
        'Getting started' => 'A demo project, an importer for existing Forge/Ploi/plain servers, shareable preview links, and guidance to a first successful deploy.',
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
