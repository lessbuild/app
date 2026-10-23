<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Data\Deploy\DetectedApp;
use App\Enums\ServerType;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Services\Infrastructure\ServerPricing;
use App\Services\Infrastructure\ServerRightsizing;
use App\Services\Projects\ProjectResources;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Proposes how to launch a repository: per app a website on its own domain, an environment with the right runtime,
 * production settings, the secrets its .env.example leaves empty, its database, workers and Redis, path filters when
 * it shares the repository, and a server with room for all of it (an existing one that fits, or the cheapest size
 * from a cloud provider). The proposal is what the Launch page shows to change and confirm.
 */
final class LaunchPlanner
{
    /**
     * Keys in .env.example that are secrets the app can't run without, when left empty there.
     *
     * @var string
     */
    private const SECRET = '/(_KEY|_SECRET|_TOKEN|_PASSWORD|_DSN)\z/';

    /**
     * Keys Buildpusher fills in itself, never asked for.
     *
     * @var list<string>
     */
    private const FILLED = ['APP_KEY', 'DB_PASSWORD', 'REDIS_PASSWORD'];

    /**
     * Create a new LaunchPlanner instance.
     *
     * @param  RepositoryFiles  $files  Reads the repository through its Git provider.
     * @param  StackDetector  $detector  Finds its apps.
     * @param  ProductionCheck  $check  Estimates what's already running on each server.
     * @param  ServerRightsizing  $sizes  Reads servers' sizes and providers' cheapest fitting size.
     */
    public function __construct(private readonly RepositoryFiles $files, private readonly StackDetector $detector, private readonly ProductionCheck $check, private readonly ServerRightsizing $sizes) {}

    /**
     * Propose a launch.
     *
     * @param  Project  $project
     * @param  Provider  $provider  the Git provider
     * @param  string  $url
     * @param  string  $branch
     * @param  string  $domain  the main domain, e.g. example.com; an API gets api.example.com
     * @param  Provider|null  $cloud  a cloud provider, to suggest a new server's size from
     * @param  string|null  $region
     * @param  User|null  $viewer  who's asking, so only servers they may use are offered
     * @return array{repository: array{provider_id: int, url: string, branch: string, name: string}, apps: list<array<string, mixed>>, memoryNeededMb: int, servers: list<array<string, mixed>>, recommendedSize: array{id: string, memoryGb: float, price: string|null}|null}
     */
    public function plan(Project $project, Provider $provider, string $url, string $branch, string $domain, ?Provider $cloud = null, ?string $region = null, ?User $viewer = null): array
    {
        try {
            $snapshot = $this->files->open($provider, $url, $branch);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['url' => $exception->getMessage()]);
        }
        $apps = $this->detector->detect($snapshot);
        if ($apps === []) {
            throw ValidationException::withMessages(['url' => __('No app found in :branch: nothing Buildpusher knows how to deploy.', ['branch' => $branch])]);
        }
        $domain = strtolower(trim($domain, " ./\t\n"));
        $name = Str::afterLast(rtrim($url, '/'), '/');
        $planned = [];
        $mainTaken = false;
        foreach ($apps as $index => $app) {
            $folder = $app->path === '' ? $name : Str::afterLast($app->path, '/');
            $host = match (true) {
                count($apps) === 1 => $domain,
                $app->isBackend() && ! array_any($planned, fn (array $other): bool => $other['backend']) => 'api.'.$domain,
                ! $app->isBackend() && ! $mainTaken => $domain,
                default => Str::slug($folder).'.'.$domain,
            };
            $mainTaken = $mainTaken || $host === $domain;
            $planned[] = $this->app($snapshot, $app, $index, count($apps), $folder, $host);
        }
        $need = $this->memoryNeeded($planned);
        $recommended = $cloud !== null ? $this->sizes->cheapestFor($cloud, ceil($need / 1024 / 0.85 * 2) / 2, $region) : null;
        $symbol = $cloud !== null && ServerPricing::currency($cloud->type) === 'EUR' ? '€' : '$';

        return [
            'repository' => ['provider_id' => $provider->id, 'url' => $url, 'branch' => $branch, 'name' => $name],
            'apps' => $planned,
            'memoryNeededMb' => $need,
            'servers' => array_values(array_filter($this->servers($project, $need), fn (array $server): bool => $viewer === null
                || app(ProjectResources::class)->mayUseServer($viewer, Server::query()->findOrFail((int) $server['id']), $project->id))),
            'recommendedSize' => $recommended === null ? null : ['id' => $recommended['id'], 'memoryGb' => $recommended['memory_gb'],
                'price' => $recommended['price'] !== null ? $symbol.number_format((float) $recommended['price'], 2) : null],
        ];
    }

    /**
     * Plan one app.
     *
     * @param  RepositorySnapshot  $snapshot
     * @param  DetectedApp  $app
     * @param  int  $index  its place, backends first
     * @param  int  $count  how many apps the repository has
     * @param  string  $folder  its folder's name (or the repository's, at the top level)
     * @param  string  $domain
     * @return array<string, mixed>
     */
    private function app(RepositorySnapshot $snapshot, DetectedApp $app, int $index, int $count, string $folder, string $domain): array
    {
        $laravel = $app->framework === 'laravel';
        // An app server has MySQL, and a SQLite file in a release folder is lost on the next deploy, so Laravel uses MySQL.
        $mysql = in_array($app->database, ['mysql', 'sqlite'], true) && ($laravel || $app->database === 'mysql');
        $redis = $app->redis;
        $horizon = $app->workerCommand === 'php artisan horizon';
        $variables = match (true) {
            $laravel => [
                'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_URL' => 'https://'.$domain, 'LOG_LEVEL' => 'warning', 'LOG_STACK' => 'daily',
                'CACHE_STORE' => $redis ? 'redis' : 'file', 'SESSION_DRIVER' => $redis ? 'redis' : 'file', ...($horizon ? ['QUEUE_CONNECTION' => 'redis'] : []),
            ],
            in_array($app->runtime, ['node'], true) => ['NODE_ENV' => 'production'],
            default => [],
        };
        $notes = $app->notes;
        if ($laravel && $app->database === 'sqlite') {
            $notes[] = __('It uses SQLite locally; production uses the server’s MySQL. Turn off Use MySQL to keep SQLite, in shared storage so deploys keep it.');
        }
        if ($app->database === 'pgsql') {
            $notes[] = __('It uses PostgreSQL: add a PostgreSQL server, then its settings as an external resource.');
        }

        return [
            'path' => $app->path, 'label' => $app->label, 'framework' => $app->framework, 'runtime' => $app->runtime, 'runtimeVersion' => $app->runtimeVersion,
            'backend' => $app->isBackend(), 'name' => Str::title(str_replace(['-', '_'], ' ', $folder)), 'domain' => $domain,
            'environment' => $index === 0 ? 'Production' : 'Production-'.Str::slug($folder),
            'mysql' => $mysql, 'canUseMysql' => $laravel || $app->database === 'mysql', 'redis' => $redis,
            'queue' => $app->queue && $app->workerCommand !== null, 'workerCommand' => $app->workerCommand, 'scheduler' => $app->scheduler && $laravel,
            'buildCommand' => $app->buildCommand, 'startCommand' => $app->startCommand, 'port' => $app->port,
            'healthPath' => $laravel ? '/up' : '/',
            'includePaths' => $count > 1 && $app->path !== '' ? [$app->path.'/**'] : [],
            'variables' => $variables,
            'secrets' => $this->secrets($snapshot, $app),
            'notes' => $notes,
        ];
    }

    /**
     * List the secrets the app's .env.example leaves empty (API keys, tokens, passwords), to ask for before launching.
     *
     * @param  RepositorySnapshot  $snapshot
     * @param  DetectedApp  $app
     * @return list<string>
     */
    private function secrets(RepositorySnapshot $snapshot, DetectedApp $app): array
    {
        $secrets = [];
        foreach (preg_split('/\R/', (string) $snapshot->read(ltrim($app->path.'/.env.example', '/'))) ?: [] as $line) {
            if (preg_match('/^\s*([A-Z][A-Z0-9_]*)\s*=\s*(["\']?)\s*\2\s*$/', $line, $match) === 1 && preg_match(self::SECRET, $match[1]) === 1
                && ! in_array($match[1], self::FILLED, true) && ! str_starts_with($match[1], 'VITE_')) {
                $secrets[] = $match[1];
            }
        }

        return array_values(array_unique($secrets));
    }

    /**
     * Estimate the memory, in MB, the apps need on one app server: the system, MySQL and Redis, each app by runtime,
     * and each worker.
     *
     * @param  list<array<string, mixed>>  $apps
     * @return int
     */
    public function memoryNeeded(array $apps): int
    {
        $need = ProductionCheck::MEMORY_MB['system'] + ProductionCheck::MEMORY_MB['mysql'] + ProductionCheck::MEMORY_MB['redis'];
        foreach ($apps as $app) {
            $need += ProductionCheck::MEMORY_MB[(string) $app['runtime']] ?? ProductionCheck::MEMORY_MB['php'];
            $need += ProductionCheck::MEMORY_MB['worker'] * ((int) (bool) $app['queue'] + (int) (bool) $app['scheduler']);
        }

        return $need;
    }

    /**
     * The account's active app servers, each with whether the apps fit beside what already runs there.
     *
     * @param  Project  $project
     * @param  int  $need  MB the apps need, MySQL and the system included
     * @return list<array<string, mixed>>
     */
    private function servers(Project $project, int $need): array
    {
        $apps = $need - ProductionCheck::MEMORY_MB['system'] - ProductionCheck::MEMORY_MB['mysql'] - ProductionCheck::MEMORY_MB['redis'];

        return array_values(Server::query()->where('account_id', $project->account_id)->where('type', ServerType::App)->where('provisioning_status', Server::STATUS_ACTIVE)->with('provider')->orderBy('name')->get()
            ->map(function (Server $server) use ($apps): array {
                $size = $this->sizes->size($server);
                $total = $this->check->memoryNeeded($server) + $apps;

                return ['id' => $server->id, 'label' => $server->label(), 'ip' => $server->public_ip, 'memoryGb' => $size['memory_gb'] ?? null,
                    'neededGb' => round($total / 1024, 1), 'fits' => $size === null || $total <= $size['memory_gb'] * 1024 * 0.85];
            })->all());
    }
}
