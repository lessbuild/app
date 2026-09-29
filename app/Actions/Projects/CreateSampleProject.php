<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Actions\Analytics\RebuildReportAggregates;
use App\Actions\Analytics\RebuildSiteVisits;
use App\Actions\Telemetry\RecordDeployment;
use App\Contracts\Telemetry\TelemetryIngestor;
use App\Data\Projects\ProjectDetails;
use App\Data\Telemetry\IngestContext;
use App\Models\Account;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsIngestionBatch;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a sample project to look around in before connecting anything real: Analytics with a month of made-up
 * visits and Monitoring with a day of made-up requests, errors and a release. Nothing in it reaches the outside world
 * (the site doesn't collect, and there are no servers or monitors), the visits don't count towards the plan's
 * pageviews, and it's labelled as a sample everywhere so it can be deleted when you're done.
 */
final class CreateSampleProject
{
    /**
     * Pages the made-up visitors read, with how popular each is.
     *
     * @var array<string, int>
     */
    private const PAGES = ['/' => 40, '/pricing' => 18, '/products/coffee-grinder' => 12, '/products/kettle' => 9, '/blog/brewing-guide' => 8, '/checkout' => 6, '/welcome' => 4, '/about' => 3];

    /**
     * Where the made-up visitors came from: [referrer host, utm source, utm medium, utm campaign], with weights.
     *
     * @var list<array{0: string|null, 1: string|null, 2: string|null, 3: string|null, 4: int}>
     */
    private const SOURCES = [[null, null, null, null, 35], ['google.com', null, null, null, 30], ['news.ycombinator.com', null, null, null, 8], ['twitter.com', null, null, null, 6], [null, 'newsletter', 'email', 'autumn-sale', 12], ['duckduckgo.com', null, null, null, 9]];

    /**
     * Create a new CreateSampleProject instance.
     *
     * @param  CreateProject  $projects  Creates the project (and checks the plan allows another).
     * @param  EnableService  $services  Turns Monitoring and Analytics on.
     * @param  RebuildSiteVisits  $visits  Groups the made-up events into visits.
     * @param  RebuildReportAggregates  $aggregates  Builds the Analytics reports from them.
     * @param  TelemetryIngestor  $telemetry  Takes in the made-up requests and errors.
     * @param  RecordDeployment  $deployments  Marks a release.
     */
    public function __construct(
        private readonly CreateProject $projects,
        private readonly EnableService $services,
        private readonly RebuildSiteVisits $visits,
        private readonly RebuildReportAggregates $aggregates,
        private readonly TelemetryIngestor $telemetry,
        private readonly RecordDeployment $deployments,
    ) {}

    /**
     * Create the sample project in an account.
     *
     * @param  User  $actor
     * @param  Account  $account
     * @return Project
     */
    public function handle(User $actor, Account $account): Project
    {
        $project = $this->projects->handle($actor, $account, new ProjectDetails(__('Sample storefront'), __('Made-up data to look around with. Delete it whenever you like.')));
        $project->forceFill(['is_sample' => true, 'checklist_dismissed_at' => now()])->save();
        foreach (['monitoring', 'analytics'] as $service) {
            $this->services->handle($actor, $project, $service);
        }
        $this->analytics($project);
        $this->monitoring($project, $actor);

        return $project;
    }

    /**
     * Make a month of visits to a sample site and build its reports from them.
     *
     * @param  Project  $project
     * @return void
     */
    private function analytics(Project $project): void
    {
        $site = new AnalyticsSite;
        $site->forceFill(['project_id' => $project->id, 'name' => 'storefront.example', 'domains' => ['storefront.example'], 'timezone' => 'UTC', 'verified_at' => now(), 'collection_enabled' => false])->save();
        $batch = AnalyticsIngestionBatch::query()->create(['site_id' => $site->id, 'batch_id' => Str::uuid()->toString(), 'event_count' => 0, 'status' => 'processing', 'accepted_at' => now()]);
        $rows = [];
        $devices = [['desktop', 'Chrome', 'macOS', 45], ['desktop', 'Firefox', 'Windows', 15], ['mobile', 'Safari', 'iOS', 25], ['mobile', 'Chrome', 'Android', 15]];
        for ($day = 29; $day >= 0; $day--) {
            // A gentle upward trend with weekday bumps.
            $date = now('UTC')->subDays($day)->startOfDay();
            $visitors = (int) round((18 + (29 - $day) * 0.8) * ($date->isWeekend() ? 0.7 : 1.0)) + random_int(0, 6);
            for ($visitor = 0; $visitor < $visitors; $visitor++) {
                [$device, $browser, $system] = $this->pick($devices, 3);
                [$referrer, $source, $medium, $campaign] = $this->pick(self::SOURCES, 4);
                $hash = hash('sha256', "sample-{$project->id}-{$day}-{$visitor}");
                $session = Str::uuid()->toString();
                // Spread across the day, and only up to now on today.
                $latest = $day === 0 ? max(60, (int) $date->diffInSeconds(now('UTC')->subMinutes(20))) : 22 * 3600;
                $at = $date->copy()->addSeconds(random_int(min(7 * 3600, $latest - 60), $latest));
                if ($at->isFuture()) {
                    continue;
                }
                foreach (range(1, random_int(1, 4)) as $view) {
                    $path = $view === 1 ? (string) $this->pickKey(self::PAGES) : (string) $this->pickKey(self::PAGES);
                    $rows[] = [
                        'site_id' => $site->id, 'ingestion_batch_id' => $batch->id, 'event_id' => Str::uuid()->toString(), 'type' => 'pageview',
                        'occurred_at' => $at, 'received_at' => $at, 'path' => $path, 'referrer_host' => $view === 1 ? $referrer : null,
                        'utm_source' => $view === 1 ? $source : null, 'utm_medium' => $view === 1 ? $medium : null, 'utm_campaign' => $view === 1 ? $campaign : null,
                        'device_category' => $device, 'browser' => $browser, 'operating_system' => $system, 'visitor_hash' => $hash, 'session_id' => $session,
                        'properties' => null, 'created_at' => now(), 'updated_at' => now(),
                    ];
                    $at = $at->copy()->addSeconds(random_int(20, 240));
                    if ($at->isFuture()) {
                        break;
                    }
                }
            }
        }
        DB::transaction(function () use ($rows, $batch, $site): void {
            foreach (array_chunk($rows, 500) as $chunk) {
                AnalyticsEvent::query()->insert($chunk);
            }
            $batch->update(['event_count' => count($rows)]);
            $this->visits->handle($site, $batch);
            $this->aggregates->handle($site, $batch);
            $batch->update(['status' => 'processed', 'processed_at' => now()]);
            $site->forceFill(['last_event_at' => now(), 'last_processed_at' => now()])->save();
        });
    }

    /**
     * Send a day of requests and a few recurring errors into Monitoring, around a release.
     *
     * @param  Project  $project
     * @param  User  $actor
     * @return void
     */
    private function monitoring(Project $project, User $actor): void
    {
        $environment = $project->environments()->where('slug', 'production')->firstOrFail();
        $release = now('UTC')->subHours(9);
        $this->deployments->handle($environment, ['deployment_id' => Str::uuid()->toString(), 'version' => 'v1.4.0', 'service' => 'storefront', 'service_namespace' => null, 'commit_sha' => 'a71c8ef', 'note' => __('Sample release'), 'deployed_at' => $release->toIso8601String()], $actor);
        $routes = [['GET /', '/', 45, 90], ['GET /pricing', '/pricing', 20, 110], ['GET /products/{product}', '/products/{product}', 25, 140], ['POST /checkout', '/checkout', 10, 420]];
        $errors = [['PaymentDeclinedException', 'Card was declined by the payment gateway', '/checkout'], ['QueryException', 'Deadlock found when trying to get lock', '/checkout'], ['TypeError', 'Argument #1 ($price) must be of type int, null given', '/products/{product}']];
        $events = [];
        for ($minute = 24 * 60; $minute > 0; $minute -= 6) {
            $at = now('UTC')->subMinutes($minute);
            [$name, $route, , $typical] = $this->pick($routes, 2);
            $slow = $at->greaterThan($release) && $route === '/checkout';
            $events[] = ['id' => Str::uuid()->toString(), 'type' => 'request', 'name' => $name, 'route' => $route, 'service' => 'storefront', 'status_code' => 200,
                'duration_ms' => $typical * ($slow ? 1.8 : 1.0) + random_int(0, 60), 'timestamp' => $at->toIso8601String()];
            if (random_int(1, 12) === 1 || ($slow && random_int(1, 4) === 1)) {
                [$class, $message, $errorRoute] = $errors[$slow ? 0 : random_int(0, 2)];
                $events[] = ['id' => Str::uuid()->toString(), 'type' => 'exception', 'severity' => 'error', 'name' => $class, 'title' => $message, 'route' => $errorRoute,
                    'service' => 'storefront', 'fingerprint' => sha1($class.$errorRoute), 'timestamp' => $at->toIso8601String(), 'details' => "{$class}: {$message}\n#0 app/Http/Controllers/CheckoutController.php(42)"];
            }
        }
        foreach (array_chunk($events, 200) as $index => $chunk) {
            $this->telemetry->ingest($environment, 'sample-'.$project->id.'-'.$index, $chunk, new IngestContext);
        }
    }

    /**
     * Pick one row of a weighted list, the weight being the row's given column.
     *
     * @param  list<array<int, mixed>>  $rows
     * @param  int  $weightColumn
     * @return array<int, mixed>
     */
    private function pick(array $rows, int $weightColumn): array
    {
        $total = array_sum(array_map(fn (array $row): int => (int) $row[$weightColumn], $rows));
        $roll = random_int(1, max(1, $total));
        foreach ($rows as $row) {
            $roll -= (int) $row[$weightColumn];
            if ($roll <= 0) {
                return $row;
            }
        }

        return $rows[0];
    }

    /**
     * Pick a key of a weighted map.
     *
     * @param  array<string, int>  $weights
     * @return string
     */
    private function pickKey(array $weights): string
    {
        $roll = random_int(1, array_sum($weights));
        foreach ($weights as $key => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $key;
            }
        }

        return (string) array_key_first($weights);
    }
}
