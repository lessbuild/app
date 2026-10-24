<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Puts the platform health & resilience plan (docs/platform-health-plan.md) on the public roadmap as planned. Each
 * stage's own migration marks it shipped.
 */
return new class extends Migration
{
    /**
     * The roadmap items, by title.
     *
     * @var array<string, string>
     */
    public const ITEMS = [
        'Proactive server health alerts' => 'Buildpusher alerts you when a server is running low on disk or memory, or has a queue backing up with no worker to drain it — instead of waiting for you to notice.',
        'Fleet security posture' => 'One view of every server’s PHP, Node and Ubuntu versions and pending security updates, with a warning when a runtime is near end of life and a one-click patch-all.',
        'Hands-off deploys' => 'Deploy automatically when a build passes (opt-in), with pre-flight checks for disk, memory and a healthy worker so a deploy stops with a clear reason instead of failing part-way.',
        'Defaults sized to the server' => 'Release retention and server-size advice scale to the machine, so a small disk isn’t filled by keeping too many releases.',
        'Platform dependency scanning' => 'Buildpusher scans its own dependencies for security advisories, the same way it already scans the apps you deploy.',
        'Clearer failure messages' => 'Provisioning and deploy failures explain what went wrong and what to do, instead of showing a raw error or command.',
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
