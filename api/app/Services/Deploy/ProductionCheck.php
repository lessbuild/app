<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Data\Deploy\ProductionIssue;
use App\Enums\EnvironmentKind;
use App\Enums\ServerType;
use App\Models\Environment;
use App\Models\EnvironmentProcess;
use App\Models\EnvironmentResource;
use App\Models\Repository;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\Website;
use App\Services\Infrastructure\ServerPricing;
use App\Services\Infrastructure\ServerRightsizing;

/**
 * Checks an environment the way a careful reviewer would before going live: Laravel settings left at their development
 * defaults (debug mode, debug logging, one log file, database sessions and cache, a trailing slash in APP_URL), queued
 * jobs with nothing running them, and servers that are too full or too small for what runs on them. Each issue says
 * why it matters and, where Buildpusher can, how one click fixes it. Reading never changes anything.
 */
final class ProductionCheck
{
    /**
     * Rough memory, in MB, of what runs on a server, for judging whether it fits: the system with Caddy and the agent,
     * MySQL and Redis on app servers, each website by runtime, and each worker.
     *
     * @var array<string, int>
     */
    public const MEMORY_MB = ['system' => 350, 'mysql' => 400, 'redis' => 50, 'php' => 200, 'node' => 250, 'python' => 200, 'docker' => 300, 'compose' => 400, 'worker' => 80];

    /**
     * Create a new ProductionCheck instance.
     *
     * @param  ServerRightsizing  $sizes  Reads the server's size and the provider's other sizes.
     */
    public function __construct(private readonly ServerRightsizing $sizes) {}

    /**
     * List the environment's issues, most serious first.
     *
     * @param  Environment  $environment
     * @return list<ProductionIssue>
     */
    public function issues(Environment $environment): array
    {
        $environment->loadMissing(['variables', 'processes', 'resources', 'repositories.website.server.provider']);
        $issues = [...$this->laravel($environment), ...$this->workers($environment)];
        foreach ($environment->repositories->pluck('website.server')->filter()->unique('id') as $server) {
            $issues = [...$issues, ...$this->server($environment, $server)];
        }
        $order = ['critical' => 0, 'warning' => 1, 'notice' => 2];
        usort($issues, fn (ProductionIssue $a, ProductionIssue $b): int => $order[$a->severity] <=> $order[$b->severity]);

        return $issues;
    }

    /**
     * Get the values the app sees: the environment's runtime variables with its resources' on top, as deploys write them.
     *
     * @param  Environment  $environment
     * @return array<string, string>
     */
    public function values(Environment $environment): array
    {
        $values = $environment->variables->whereIn('scope', ['runtime', 'all'])->mapWithKeys(fn ($variable): array => [$variable->key => (string) $variable->value])->all();
        foreach ($environment->resources as $resource) {
            foreach ((array) ($resource->configuration['variables'] ?? []) as $key => $value) {
                $values[(string) $key] = (string) $value;
            }
        }

        return $values;
    }

    /**
     * Whether the environment runs a Laravel app: a PHP runtime whose settings use Laravel's names.
     *
     * @param  Environment  $environment
     * @param  array<string, string>  $values
     * @return bool
     */
    public function isLaravel(Environment $environment, array $values): bool
    {
        return in_array($environment->runtime_type ?? 'php', ['php', 'docker', 'compose'], true)
            && (isset($values['APP_KEY']) || $environment->processes->contains(fn (EnvironmentProcess $process): bool => str_contains($process->command, 'artisan')));
    }

    /**
     * Check Laravel's own settings.
     *
     * @param  Environment  $environment
     * @return list<ProductionIssue>
     */
    private function laravel(Environment $environment): array
    {
        $values = $this->values($environment);
        if (! $this->isLaravel($environment, $values)) {
            return [];
        }
        $production = $environment->kind === EnvironmentKind::Production;
        $issues = [];
        $on = fn (?string $value): bool => in_array(strtolower(trim((string) $value, " \"'")), ['true', '1', 'on', 'yes'], true);

        if ($production && $on($values['APP_DEBUG'] ?? null)) {
            $issues[] = new ProductionIssue('app-debug', 'critical', __('Debug mode is on'), __('Error pages show your code, settings and secrets to anyone who triggers an error.'), __('Turn debug mode off'), ['APP_DEBUG' => 'false']);
        }
        if ($production && isset($values['APP_ENV']) && $values['APP_ENV'] !== 'production') {
            $issues[] = new ProductionIssue('app-env', 'warning', __('APP_ENV is :value, not production', ['value' => $values['APP_ENV']]), __('Laravel and its packages relax their safety checks outside production.'), __('Set APP_ENV to production'), ['APP_ENV' => 'production']);
        }
        $url = $values['APP_URL'] ?? null;
        if ($url !== null && $url !== '' && (str_ends_with($url, '/') || ($production && str_starts_with($url, 'http://')))) {
            $fixed = rtrim($production ? (string) preg_replace('#^http://#', 'https://', $url) : $url, '/');
            $issues[] = new ProductionIssue('app-url', 'warning', __('APP_URL should be :url', ['url' => $fixed]), __('A trailing slash puts // in some links Laravel and its packages build, and http:// links redirect or are blocked.'), __('Set APP_URL to :url', ['url' => $fixed]), ['APP_URL' => $fixed]);
        }
        if ($production && in_array(strtolower($values['LOG_LEVEL'] ?? 'debug'), ['debug'], true)) {
            $issues[] = new ProductionIssue('log-level', 'warning', __('Everything is logged, down to debug messages'), __('LOG_LEVEL defaults to debug, which writes every query and message to disk on a busy site.'), __('Log warnings and above'), ['LOG_LEVEL' => 'warning']);
        }
        if ($production && ! isset($values['LOG_STACK']) && in_array($values['LOG_CHANNEL'] ?? 'stack', ['stack', 'single'], true)) {
            $issues[] = new ProductionIssue('log-stack', 'notice', __('The log is one file that never stops growing'), __('Laravel’s default single log isn’t rotated, so it fills the disk over time. A daily log keeps 14 days.'), __('Use a daily log'), ['LOG_STACK' => 'daily']);
        }
        $cache = $this->cacheStore($environment);
        $replicas = max(1, (int) $environment->maximum_replicas);
        $labels = [
            'CACHE_STORE' => ['title' => __('The cache uses the database'), 'fix' => fn (string $store): string => __('Use :store for the cache', ['store' => $store])],
            'SESSION_DRIVER' => ['title' => __('Sessions use the database'), 'fix' => fn (string $store): string => __('Use :store for sessions', ['store' => $store])],
        ];
        foreach ($labels as $key => $label) {
            if (strtolower($values[$key] ?? 'database') !== 'database') {
                continue;
            }
            if ($cache === null && $replicas > 1) {
                $issues[] = new ProductionIssue(strtolower($key), 'notice', $label['title'], __('Every request reads and writes them in the database. With more than one server, add a Redis or Valkey resource and use it instead.'));

                continue;
            }
            $store = $cache ?? 'file';
            $issues[] = new ProductionIssue(strtolower($key), 'notice', $label['title'],
                $cache !== null ? __('Every request reads and writes them in the database, though the environment has Redis.') : __('Every request reads and writes them in the database. Files on the server are faster for a single server.'),
                $label['fix']($store), [$key => $store]);
        }

        return $issues;
    }

    /**
     * Check that queued jobs have something running them.
     *
     * @param  Environment  $environment
     * @return list<ProductionIssue>
     */
    private function workers(Environment $environment): array
    {
        $values = $this->values($environment);
        if (! $this->isLaravel($environment, $values) || strtolower($values['QUEUE_CONNECTION'] ?? 'database') === 'sync') {
            return [];
        }
        $running = $environment->processes->contains(fn (EnvironmentProcess $process): bool => $process->is_enabled && preg_match('/artisan\s+(queue:(work|listen)|horizon)\b/', $process->command) === 1);

        return $running ? [] : [new ProductionIssue('queue-worker', 'warning', __('Queued jobs have nothing running them'), __('Mail, notifications and jobs that are queued wait forever without a queue worker.'),
            __('Add a queue worker'), process: ['name' => 'queue', 'type' => 'worker', 'command' => 'php artisan queue:work --tries=3 --max-time=3600'])];
    }

    /**
     * Check the server the environment's websites run on: its disk, and its memory against what runs there.
     *
     * @param  Environment  $environment
     * @param  Server  $server
     * @return list<ProductionIssue>
     */
    private function server(Environment $environment, Server $server): array
    {
        $name = $server->display_name ?: $server->name;
        $url = "/projects/{$environment->project_id}/infrastructure/servers/{$server->id}";
        $issues = [];
        $latest = ServerMetric::query()->where('server_id', $server->id)->latest('recorded_at')->first();
        if ($latest !== null && $latest->disk_percent >= 85) {
            $issues[] = new ProductionIssue("server-{$server->id}-disk", $latest->disk_percent >= 95 ? 'critical' : 'warning', __(':server’s disk is :percent% full', ['server' => $name, 'percent' => $latest->disk_percent]),
                __('Deploys, logs and the database stop working when it fills up. Disk clean-up on the server’s Overview shows what’s using the space.'), url: $url);
        }
        $size = $this->sizes->size($server);
        $need = $this->memoryNeeded($server);
        if ($size !== null && $need > $size['memory_gb'] * 1024 * 0.85) {
            $suggested = $this->sizes->smallestWith($server, ceil($need / 1024 / 0.85 * 2) / 2);
            $issues[] = new ProductionIssue("server-{$server->id}-memory", 'warning', __(':server is too small for what runs on it', ['server' => $name]),
                $suggested !== null
                    ? __('It has :have GB of memory and what runs on it needs about :need GB, so it swaps to disk and slows down. :size (:memory GB) fits, for :price a month.', ['have' => rtrim(rtrim(number_format($size['memory_gb'], 1), '0'), '.'), 'need' => number_format($need / 1024, 1), 'size' => $suggested['id'], 'memory' => rtrim(rtrim(number_format($suggested['memory_gb'], 1), '0'), '.'), 'price' => ServerPricing::format((float) $suggested['price'], $server)])
                    : __('It has :have GB of memory and what runs on it needs about :need GB, so it swaps to disk and slows down.', ['have' => rtrim(rtrim(number_format($size['memory_gb'], 1), '0'), '.'), 'need' => number_format($need / 1024, 1)]),
                url: $url);
        }

        return $issues;
    }

    /**
     * Estimate the memory, in MB, of everything on a server.
     *
     * @param  Server  $server
     * @return int
     */
    public function memoryNeeded(Server $server): int
    {
        $need = self::MEMORY_MB['system'];
        if (in_array($server->type, [ServerType::App, ServerType::Database], true)) {
            $need += self::MEMORY_MB['mysql'];
        }
        if (in_array($server->type, [ServerType::App, ServerType::Cache], true)) {
            $need += self::MEMORY_MB['redis'];
        }
        foreach (Website::query()->where('server_id', $server->id)->with('environment.processes')->get() as $website) {
            // A website deploys from its repository's environment, which can differ from the one it was created in.
            $environment = Repository::query()->where('website_id', $website->id)->with('environment.processes')->first()->environment ?? $website->environment;
            $runtime = $environment->runtime_type ?? 'php';
            $need += self::MEMORY_MB[$runtime] ?? self::MEMORY_MB['php'];
            foreach ($environment->processes ?? [] as $process) {
                // Laravel workers aren't installed on Node and Python sites (see ConfigureProcessesScript).
                $skipped = in_array($runtime, ['node', 'python'], true) && str_contains($process->command, 'artisan');
                if ($process->is_enabled && ! $skipped) {
                    $need += self::MEMORY_MB['worker'] * max(1, (int) $process->replicas);
                }
            }
        }

        return $need;
    }

    /**
     * Get the cache store a managed Redis or Valkey resource provides, if the environment has one.
     *
     * @param  Environment  $environment
     * @return string|null
     */
    private function cacheStore(Environment $environment): ?string
    {
        return $environment->resources->contains(fn (EnvironmentResource $resource): bool => in_array($resource->type, ['redis', 'valkey'], true)) ? 'redis' : null;
    }
}
