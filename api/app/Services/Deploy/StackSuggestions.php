<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Data\Deploy\DetectedApp;
use App\Data\Deploy\ProductionIssue;
use App\Models\EnvironmentProcess;
use App\Models\EnvironmentResource;
use App\Models\Repository;

/**
 * Compares what a repository's app needs (from its last inspection) with what its environment has, and suggests
 * what's missing: a queue worker, the scheduler, Redis, a database, the right subdirectory and runtime. Each is a
 * ProductionIssue so the page shows them the same way; the fixable ones are applied by ApplyStackSuggestion.
 */
final class StackSuggestions
{
    /**
     * Get the app this repository deploys: the one in its subdirectory, or the only one when there's no subdirectory.
     *
     * @param  Repository  $repository
     * @return DetectedApp|null
     */
    public function app(Repository $repository): ?DetectedApp
    {
        $apps = array_map(DetectedApp::fromArray(...), $repository->stack ?? []);
        $root = trim((string) $repository->deployment_root, '/');
        foreach ($apps as $app) {
            if ($app->path === $root) {
                return $app;
            }
        }

        return $root === '' && count($apps) === 1 ? $apps[0] : null;
    }

    /**
     * List what the repository's environment is missing for its app.
     *
     * @param  Repository  $repository
     * @return list<ProductionIssue>
     */
    public function suggestions(Repository $repository): array
    {
        $apps = array_map(DetectedApp::fromArray(...), $repository->stack ?? []);
        $app = $this->app($repository);
        $root = trim((string) $repository->deployment_root, '/');
        if ($app === null) {
            $folders = array_values(array_filter(array_map(fn (DetectedApp $app): string => $app->path, $apps)));

            return $root === '' && $folders !== []
                ? [new ProductionIssue('subdirectory', 'warning', __('The apps are in :folders, not the top level', ['folders' => implode(', ', $folders)]),
                    __('Set Subdirectory in the repository’s Settings to the app this website runs, so its deploys build that folder.'))]
                : [];
        }
        $environment = $repository->environment;
        if ($environment === null) {
            return [];
        }
        $environment->loadMissing(['processes', 'resources', 'variables']);
        $suggestions = [];
        $runtime = $environment->runtime_type ?? 'php';
        if ($runtime !== $app->runtime) {
            $suggestions[] = new ProductionIssue('runtime', 'warning', __(':app runs on :runtime, but the environment is set to :current', ['app' => $app->label, 'runtime' => self::runtimeLabel($app->runtime), 'current' => self::runtimeLabel($runtime)]),
                __('Choose :runtime under How deploys run on the environment’s Settings tab.', ['runtime' => self::runtimeLabel($app->runtime)]));
        }
        $processes = $environment->processes->where('is_enabled', true);
        if ($app->queue && $app->workerCommand !== null && ! $processes->contains(fn (EnvironmentProcess $process): bool => $process->type === 'worker')) {
            $suggestions[] = new ProductionIssue('queue', 'warning', __(':app queues jobs, and nothing runs them', ['app' => $app->label]), __('Queued mail, notifications and jobs wait until a queue worker picks them up.'),
                __('Add a queue worker'), process: ['name' => 'queue', 'type' => 'worker', 'command' => $app->workerCommand]);
        }
        if ($app->framework === 'laravel' && $app->scheduler && ! $processes->contains(fn (EnvironmentProcess $process): bool => $process->type === 'scheduler')) {
            $suggestions[] = new ProductionIssue('scheduler', 'warning', __(':app schedules tasks, and nothing runs the scheduler', ['app' => $app->label]), __('Scheduled commands never run without it.'),
                __('Add the scheduler'), process: ['name' => 'scheduler', 'type' => 'scheduler', 'command' => 'php artisan schedule:work']);
        }
        $resources = $environment->resources;
        if ($app->redis && ! $resources->contains(fn (EnvironmentResource $resource): bool => in_array($resource->type, ['redis', 'valkey'], true))) {
            $suggestions[] = new ProductionIssue('redis', 'notice', __(':app uses Redis, and the environment has none', ['app' => $app->label]), __('A managed Redis runs on the server and its settings go into .env.'), __('Add Redis'), resource: ['name' => 'redis', 'type' => 'redis']);
        }
        $hasDatabase = $resources->contains(fn (EnvironmentResource $resource): bool => in_array($resource->type, ['mysql', 'postgresql'], true)) || $environment->variables->contains('key', 'DB_HOST');
        if ($app->database === 'mysql' && ! $hasDatabase && $repository->website->has_database) {
            $suggestions[] = new ProductionIssue('database', 'notice', __(':app uses MySQL, and the environment isn’t connected to a database', ['app' => $app->label]), __('Connect it to the website’s MySQL database; its settings go into .env.'), __('Connect the database'), resource: ['name' => 'database', 'type' => 'mysql']);
        }

        return $suggestions;
    }

    /**
     * The name of a runtime as the Settings tab shows it.
     *
     * @param  string  $runtime
     * @return string
     */
    public static function runtimeLabel(string $runtime): string
    {
        return match ($runtime) {
            'node' => 'Node.js',
            'python' => 'Python',
            'docker' => 'Docker',
            'compose' => 'Docker Compose',
            default => 'PHP',
        };
    }
}
