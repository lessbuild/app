<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use App\Data\Analytics\ReportPeriod;
use App\Models\Account;
use App\Models\AnalyticsSite;
use App\Models\Build;
use App\Models\Incident;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Monitor;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\TelemetryEvent;
use App\Models\User;
use App\Queries\Analytics\AnalyticsReportQuery;
use App\Support\Telemetry\EventTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The read-only tools the assistant answers with: projects, deploys and their impact, errors, uptime checks,
 * incidents, Analytics and servers. They're shared by the in-app assistant (Claude calls them) and the MCP endpoint
 * (people's own AI tools call them). Every tool only sees what the person may see: projects they can view, services
 * their membership includes and, through a token, the token's scopes.
 */
class AssistantTools
{
    /**
     * Each tool: what it's for, the token scope it needs, and its input schema.
     *
     * @var array<string, array{description: string, scope: string, properties: array<string, array<string, mixed>>, required: list<string>}>
     */
    private const array TOOLS = [
        'list_projects' => [
            'description' => 'List the projects in the account with their services and environments. Start here to find project and environment IDs.',
            'scope' => 'projects:read', 'properties' => [], 'required' => [],
        ],
        'recent_deploys' => [
            'description' => 'List recent deploys, newest first: status, commit, when it went live, why it failed, and the release analysis (error rate, latency and conversion before and after).',
            'scope' => 'deploy:read',
            'properties' => [
                'project_id' => ['type' => 'string', 'description' => 'Only this project.'],
                'environment_id' => ['type' => 'string', 'description' => 'Only this environment.'],
                'limit' => ['type' => 'integer', 'description' => 'How many, 1 to 20 (default 10).'],
            ],
            'required' => [],
        ],
        'deploy_impact' => [
            'description' => 'Compare an environment before and after a deploy went live, over the same number of hours each side: requests, failed-request rate, average latency, exceptions, and the error issues first seen after it.',
            'scope' => 'monitoring:read',
            'properties' => [
                'build_id' => ['type' => 'integer', 'description' => 'The deploy (from recent_deploys).'],
                'hours' => ['type' => 'integer', 'description' => 'Hours on each side, 1 to 48 (default 2).'],
            ],
            'required' => ['build_id'],
        ],
        'error_issues' => [
            'description' => 'List a project’s error issues seen recently, most recent first: title, where, how often, how many users, first and last seen.',
            'scope' => 'monitoring:read',
            'properties' => [
                'project_id' => ['type' => 'string'],
                'hours' => ['type' => 'integer', 'description' => 'Seen in the last this many hours, 1 to 720 (default 24).'],
                'include_resolved' => ['type' => 'boolean'],
            ],
            'required' => ['project_id'],
        ],
        'monitors' => [
            'description' => 'List a project’s uptime and other checks with their health, failure streak and last check.',
            'scope' => 'monitoring:read',
            'properties' => ['project_id' => ['type' => 'string']],
            'required' => ['project_id'],
        ],
        'incidents' => [
            'description' => 'List incidents, newest first: title, status, when they opened, were acknowledged and resolved, and the post-mortem summary.',
            'scope' => 'monitoring:read',
            'properties' => [
                'project_id' => ['type' => 'string', 'description' => 'Only this project.'],
                'open_only' => ['type' => 'boolean'],
            ],
            'required' => [],
        ],
        'analytics_summary' => [
            'description' => 'Summarise an Analytics site over the last 1, 7, 30 or 90 days against the period before: visitors, pageviews, bounce rate, visit duration and conversions, with the top pages and sources. Sites are listed by list_analytics_sites.',
            'scope' => 'analytics:read',
            'properties' => [
                'site_id' => ['type' => 'integer'],
                'days' => ['type' => 'integer', 'description' => '1, 7, 30 or 90 (default 7).'],
            ],
            'required' => ['site_id'],
        ],
        'list_analytics_sites' => [
            'description' => 'List the Analytics sites with their project and domains.',
            'scope' => 'analytics:read', 'properties' => [], 'required' => [],
        ],
        'servers' => [
            'description' => 'List the account’s servers with their type, region, status and latest CPU, memory and disk use.',
            'scope' => 'infrastructure:read', 'properties' => [], 'required' => [],
        ],
    ];

    /**
     * Create a new AssistantTools instance.
     *
     * @param  AnalyticsReportQuery  $reports  Builds Analytics summaries.
     */
    public function __construct(private readonly AnalyticsReportQuery $reports) {}

    /**
     * Get the tools' definitions in Anthropic's shape (input_schema) or MCP's (inputSchema), optionally only those a
     * token's scopes allow.
     *
     * @param  string  $schemaKey  input_schema or inputSchema
     * @param  (callable(string): bool)|null  $allows  Whether a scope is allowed; null allows all.
     * @return list<array<string, mixed>>
     */
    public function definitions(string $schemaKey = 'input_schema', ?callable $allows = null): array
    {
        $definitions = [];
        foreach (self::TOOLS as $name => $tool) {
            if ($allows !== null && ! $allows($tool['scope'])) {
                continue;
            }
            $schema = ['type' => 'object', 'properties' => (object) $tool['properties']];
            if ($tool['required'] !== []) {
                $schema['required'] = $tool['required'];
            }
            $definitions[] = ['name' => $name, 'description' => $tool['description'], $schemaKey => $schema];
        }

        return $definitions;
    }

    /**
     * Get the token scope a tool needs, or null for an unknown tool.
     *
     * @param  string  $name
     * @return string|null
     */
    public function scope(string $name): ?string
    {
        return self::TOOLS[$name]['scope'] ?? null;
    }

    /**
     * Run a tool for a person in an account and return its result.
     *
     * @param  string  $name
     * @param  array<string, mixed>  $arguments
     * @param  Account  $account
     * @param  User  $user
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException for an unknown tool or bad arguments
     */
    public function call(string $name, array $arguments, Account $account, User $user): array
    {
        return match ($name) {
            'list_projects' => ['projects' => $this->projects($account, $user)->map(fn (Project $project): array => [
                'id' => $project->id, 'name' => $project->name,
                'services' => $project->enabledServices->pluck('service')->values()->all(),
                'environments' => $project->environments->map(fn ($environment): array => ['id' => $environment->id, 'name' => $environment->name])->values()->all(),
            ])->values()->all()],
            'recent_deploys' => ['deploys' => $this->recentDeploys($account, $user, $arguments)],
            'deploy_impact' => $this->deployImpact($account, $user, $arguments),
            'error_issues' => ['issues' => $this->errorIssues($account, $user, $arguments)],
            'monitors' => ['monitors' => $this->monitors($account, $user, $arguments)],
            'incidents' => ['incidents' => $this->incidents($account, $user, $arguments)],
            'analytics_summary' => $this->analyticsSummary($account, $user, $arguments),
            'list_analytics_sites' => ['sites' => $this->sites($account, $user)->map(fn (AnalyticsSite $site): array => [
                'id' => $site->id, 'name' => $site->name, 'project_id' => $site->project_id, 'domains' => $site->domains,
            ])->values()->all()],
            'servers' => ['servers' => $this->servers($account, $user)],
            default => throw new InvalidArgumentException("There's no tool called {$name}."),
        };
    }

    /**
     * Get the projects the person can see, optionally only those with a service they may use.
     *
     * @param  Account  $account
     * @param  User  $user
     * @param  string|null  $service
     * @return Collection<int, Project>
     */
    private function projects(Account $account, User $user, ?string $service = null): Collection
    {
        $membership = Membership::query()->where('account_id', $account->id)->where('user_id', $user->id)->first();
        if ($membership === null || ($service !== null && ! $membership->canUseService($service))) {
            return collect();
        }

        return Project::query()->where('account_id', $account->id)->with(['enabledServices', 'environments'])->orderBy('name')->get()
            ->filter(fn (Project $project): bool => $user->can('view', $project) && ($service === null || $project->hasService($service)))->values();
    }

    /**
     * Find one of the person's projects with a service.
     *
     * @param  Account  $account
     * @param  User  $user
     * @param  mixed  $id
     * @param  string  $service
     * @return Project
     */
    private function project(Account $account, User $user, mixed $id, string $service): Project
    {
        $project = $this->projects($account, $user, $service)->firstWhere('id', is_string($id) ? $id : '');

        return $project ?? throw new InvalidArgumentException("No project {$this->text($id)} with ".Str::title($service).' that you can see. Call list_projects for the IDs.');
    }

    /**
     * List recent deploys in the person's projects with Deploy.
     *
     * @param  Account  $account
     * @param  User  $user
     * @param  array<string, mixed>  $arguments
     * @return array<int, array<string, mixed>>
     */
    private function recentDeploys(Account $account, User $user, array $arguments): array
    {
        $projects = $this->projects($account, $user, 'deploy');
        if (isset($arguments['project_id'])) {
            $projects = $projects->where('id', $arguments['project_id']);
        }
        $query = Build::query()->with(['environment', 'repository'])->whereHas('repository', fn ($repositories) => $repositories->whereIn('project_id', $projects->pluck('id')))
            ->latest('id')->limit($this->integer($arguments['limit'] ?? null, 1, 20, 10));
        if (isset($arguments['environment_id'])) {
            $query->where('environment_id', $this->text($arguments['environment_id']));
        }

        return $query->get()->filter(fn (Build $build): bool => $user->can('view', $build))->map(fn (Build $build): array => [
            'id' => $build->id, 'project_id' => $build->repository->project_id, 'environment' => $build->environment?->name,
            'status' => $build->status, 'trigger' => $build->trigger_source, 'revision' => $build->revision !== null ? substr($build->revision, 0, 12) : null,
            'commit' => $build->commit_message !== null ? Str::limit(strtok($build->commit_message, "\n") ?: '', 200) : null,
            'started_at' => $build->started_at?->toIso8601String(), 'live_at' => $build->activated_at?->toIso8601String(),
            'finished_at' => $build->finished_at?->toIso8601String(), 'failure' => $build->failure_message !== null ? Str::limit($build->failure_message, 300) : null,
            'release_analysis' => $build->observation_report, 'release_analysis_status' => $build->observation_status,
        ])->values()->all();
    }

    /**
     * Compare an environment before and after a deploy went live.
     *
     * @param  Account  $account
     * @param  User  $user
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function deployImpact(Account $account, User $user, array $arguments): array
    {
        $build = Build::query()->with(['environment.project', 'repository'])->find($this->integer($arguments['build_id'] ?? null, 1, PHP_INT_MAX, 0));
        $project = $build?->environment?->project;
        if ($build === null || $project === null || ! $this->projects($account, $user, 'monitoring')->contains('id', $project->id) || ! $user->can('view', $build)) {
            throw new InvalidArgumentException('No deploy with that ID that you can see. Call recent_deploys for the IDs.');
        }
        if ($build->activated_at === null) {
            return ['build_id' => $build->id, 'went_live' => false, 'note' => 'This deploy never went live, so it can’t have changed anything.'];
        }
        $hours = $this->integer($arguments['hours'] ?? null, 1, 48, 2);
        $live = $build->activated_at;
        $window = fn (CarbonImmutable $from, CarbonImmutable $until): array => $this->environmentWindow((string) $build->environment_id, $from, $until);
        $after = $live->addHours($hours)->min(CarbonImmutable::now());

        return [
            'build_id' => $build->id, 'environment' => $build->environment->name, 'live_at' => $live->toIso8601String(), 'hours_each_side' => $hours,
            'before' => $window($live->subHours($hours), $live), 'after' => $window($live, $after),
            'new_issues_after' => Issue::query()->where('environment_id', $build->environment_id)->where('first_seen_at', '>=', $live)->where('first_seen_at', '<', $after)
                ->orderByDesc('occurrences')->limit(10)->get()->map(fn (Issue $issue): array => [
                    'id' => $issue->id, 'title' => Str::limit($issue->title, 200), 'location' => $issue->location, 'occurrences' => $issue->occurrences, 'first_seen_at' => $issue->first_seen_at->toIso8601String(),
                ])->all(),
        ];
    }

    /**
     * Count an environment's requests, failures, latency and exceptions in a window.
     *
     * @param  string  $environmentId
     * @param  CarbonImmutable  $from
     * @param  CarbonImmutable  $until
     * @return array{requests: int, failed_rate_percent: float, average_latency_ms: float|null, exceptions: int}
     */
    private function environmentWindow(string $environmentId, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $events = fn () => TelemetryEvent::query()->where('environment_id', $environmentId)
            ->where('occurred_at', '>=', EventTime::boundary($from))->where('occurred_at', '<', EventTime::boundary($until));
        $row = $events()->where('type', 'request')->toBase()->selectRaw('COUNT(*) AS requests, AVG(duration_ms) AS latency')
            ->selectRaw("COUNT(CASE WHEN status_code BETWEEN 500 AND 599 OR severity IN ('error', 'critical') THEN 1 END) AS failed")->first();
        $requests = (int) ($row->requests ?? 0);

        return [
            'requests' => $requests,
            'failed_rate_percent' => $requests > 0 ? round((int) ($row->failed ?? 0) / $requests * 100, 2) : 0.0,
            'average_latency_ms' => isset($row->latency) ? round((float) $row->latency, 1) : null,
            'exceptions' => $events()->where('type', 'exception')->count(),
        ];
    }

    /**
     * List a project's recent error issues.
     *
     * @param  Account  $account
     * @param  User  $user
     * @param  array<string, mixed>  $arguments
     * @return array<int, array<string, mixed>>
     */
    private function errorIssues(Account $account, User $user, array $arguments): array
    {
        $project = $this->project($account, $user, $arguments['project_id'] ?? null, 'monitoring');
        $query = Issue::query()->where('project_id', $project->id)->where('last_seen_at', '>=', now()->subHours($this->integer($arguments['hours'] ?? null, 1, 720, 24)))
            ->latest('last_seen_at')->limit(25);
        if (($arguments['include_resolved'] ?? false) !== true) {
            $query->whereNull('resolved_at');
        }

        return $query->get()->map(fn (Issue $issue): array => [
            'id' => $issue->id, 'title' => Str::limit($issue->title, 200), 'type' => $issue->type, 'severity' => $issue->severity, 'status' => $issue->status->value,
            'location' => $issue->location, 'occurrences' => $issue->occurrences, 'affected_users' => $issue->affected_users,
            'first_seen_at' => $issue->first_seen_at->toIso8601String(), 'last_seen_at' => $issue->last_seen_at->toIso8601String(),
        ])->all();
    }

    /**
     * List a project's checks.
     *
     * @param  Account  $account
     * @param  User  $user
     * @param  array<string, mixed>  $arguments
     * @return array<int, array<string, mixed>>
     */
    private function monitors(Account $account, User $user, array $arguments): array
    {
        $project = $this->project($account, $user, $arguments['project_id'] ?? null, 'monitoring');

        return Monitor::query()->whereIn('environment_id', $project->environments->pluck('id'))->orderBy('name')->limit(50)->get()->map(fn (Monitor $monitor): array => [
            'id' => $monitor->id, 'name' => $monitor->name, 'type' => $monitor->type, 'target' => $monitor->request_url ?? $monitor->hostname,
            'enabled' => $monitor->enabled, 'health' => $monitor->health, 'failure_streak' => $monitor->failure_streak,
            'checked_at' => $monitor->checked_at?->toIso8601String(),
        ])->all();
    }

    /**
     * List incidents in the person's projects with Monitoring.
     *
     * @param  Account  $account
     * @param  User  $user
     * @param  array<string, mixed>  $arguments
     * @return array<int, array<string, mixed>>
     */
    private function incidents(Account $account, User $user, array $arguments): array
    {
        $projects = $this->projects($account, $user, 'monitoring')->pluck('id');
        if (isset($arguments['project_id'])) {
            $projects = $projects->intersect([$this->text($arguments['project_id'])]);
        }
        $query = Incident::query()->where('account_id', $account->id)->whereIn('project_id', $projects)->latest('opened_at')->limit(20);
        if (($arguments['open_only'] ?? false) === true) {
            $query->whereNull('resolved_at');
        }

        return $query->get()->map(fn (Incident $incident): array => [
            'id' => $incident->id, 'project_id' => $incident->project_id, 'title' => $incident->title, 'status' => $incident->status,
            'opened_at' => $incident->opened_at->toIso8601String(), 'acknowledged_at' => $incident->acknowledged_at?->toIso8601String(),
            'resolved_at' => $incident->resolved_at?->toIso8601String(), 'closure_reason' => $incident->closure_reason,
            'postmortem_summary' => isset($incident->postmortem['summary']) ? Str::limit($incident->postmortem['summary'], 500) : null,
        ])->all();
    }

    /**
     * Get the Analytics sites the person may read.
     *
     * @param  Account  $account
     * @param  User  $user
     * @return Collection<int, AnalyticsSite>
     */
    private function sites(Account $account, User $user): Collection
    {
        return AnalyticsSite::query()->whereIn('project_id', $this->projects($account, $user, 'analytics')->pluck('id'))->orderBy('name')->get()
            ->filter(fn (AnalyticsSite $site): bool => $user->can('view', $site))->values();
    }

    /**
     * Summarise an Analytics site.
     *
     * @param  Account  $account
     * @param  User  $user
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function analyticsSummary(Account $account, User $user, array $arguments): array
    {
        $site = $this->sites($account, $user)->firstWhere('id', $this->integer($arguments['site_id'] ?? null, 1, PHP_INT_MAX, 0))
            ?? throw new InvalidArgumentException('No Analytics site with that ID that you can see. Call list_analytics_sites for the IDs.');
        $days = in_array($arguments['days'] ?? null, [1, 7, 30, 90], true) ? (int) $arguments['days'] : 7;
        $summary = $this->reports->handle($site, ReportPeriod::lastDays($site->timezone, $days, 'previous'));
        $metrics = [];
        foreach ($summary['metrics'] as $metric) {
            $metrics[Str::snake($metric['label'])] = ['value' => $metric['raw'] ?? null, 'change_percent' => $metric['change']];
        }

        return [
            'site' => $site->name, 'days' => $days, 'compared_with' => 'the period before', 'metrics' => $metrics,
            'top_pages' => array_slice($summary['pages'] ?? [], 0, 5), 'top_sources' => array_slice($summary['sources'] ?? [], 0, 5),
            'goals' => $summary['goals'] ?? [],
        ];
    }

    /**
     * List the account's servers with their latest resource use.
     *
     * @param  Account  $account
     * @param  User  $user
     * @return array<int, array<string, mixed>>
     */
    private function servers(Account $account, User $user): array
    {
        $membership = Membership::query()->where('account_id', $account->id)->where('user_id', $user->id)->first();
        if ($membership === null || ! $membership->canUseService('infrastructure')) {
            return [];
        }

        return Server::query()->where('account_id', $account->id)->orderBy('name')->limit(100)->get()
            ->filter(fn (Server $server): bool => $user->can('view', $server))
            ->map(function (Server $server): array {
                $metric = ServerMetric::query()->where('server_id', $server->id)->latest('recorded_at')->first();

                return [
                    'id' => $server->id, 'name' => $server->label(), 'type' => $server->type->value, 'region' => $server->region, 'size' => $server->size,
                    'status' => $server->provisioning_status, 'cpu_percent' => $metric?->cpu_percent, 'memory_percent' => $metric?->memory_percent,
                    'disk_percent' => $metric?->disk_percent, 'measured_at' => $metric?->recorded_at?->toIso8601String(),
                ];
            })->values()->all();
    }

    /**
     * Read an integer argument within bounds.
     *
     * @param  mixed  $value
     * @param  int  $min
     * @param  int  $max
     * @param  int  $default
     * @return int
     */
    private function integer(mixed $value, int $min, int $max, int $default): int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? max($min, min($max, (int) $value)) : $default;
    }

    /**
     * Read a text argument for a message.
     *
     * @param  mixed  $value
     * @return string
     */
    private function text(mixed $value): string
    {
        return is_scalar($value) ? Str::limit((string) $value, 64) : '';
    }
}
