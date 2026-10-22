<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Puts the faster set-up plan (docs/launch-plan.md) on the public roadmap as planned. Each stage's own migration marks
 * it shipped.
 */
return new class extends Migration
{
    /**
     * The roadmap items, by title.
     *
     * @var array<string, string>
     */
    public const ITEMS = [
        'Production check' => 'Each environment and deploy flags settings left at development defaults (debug mode, debug logging, database sessions, a trailing slash in APP_URL, queued jobs with no worker, a server too small for what runs on it), with one-click fixes.',
        'One database per environment' => 'A managed MySQL resource says which website’s database it uses, sites that don’t need a database don’t get one, and each Database tab shows who uses it.',
        'Stack detection' => 'Buildpusher reads a repository and finds the apps in it, their frameworks and what they need (database, queue worker, scheduler, Redis), and offers what’s missing.',
        'Launch from a repository' => 'Pick a repository and Buildpusher proposes the whole set-up on one page (server size, websites, domains, database, workers, production settings) and launches it in one go.',
        'GitHub workflow by pull request' => 'Buildpusher opens a pull request with the GitHub Actions build workflow, and another when it changes, instead of you copying the file by hand.',
        'Faster deploys' => 'Pipelines deploy steps side by side, and new repositories start with the build cache and path filters, so a change to one app doesn’t redeploy the others.',
    ];

    /**
     * Add the items, leaving any that already exist alone.
     *
     * @return void
     */
    public function up(): void
    {
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
