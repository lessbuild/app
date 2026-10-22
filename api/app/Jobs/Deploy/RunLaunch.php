<?php

declare(strict_types=1);

namespace App\Jobs\Deploy;

use App\Actions\Deploy\DeployRepository;
use App\Actions\Deploy\SaveEnvironmentBuildServer;
use App\Actions\Deploy\SaveEnvironmentProcess;
use App\Actions\Deploy\SaveEnvironmentResource;
use App\Actions\Deploy\SaveEnvironmentVariable;
use App\Actions\Deploy\SaveRepository;
use App\Actions\Deploy\UpdateBuildCache;
use App\Actions\Deploy\UpdateEnvironmentDeploySettings;
use App\Actions\Infrastructure\CreateServer;
use App\Actions\Infrastructure\CreateWebsite;
use App\Actions\Projects\CreateEnvironment;
use App\Enums\EnvironmentKind;
use App\Enums\ServerType;
use App\Models\Build;
use App\Models\Environment;
use App\Models\Launch;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Services\Deploy\GitHubWorkflowPullRequests;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Works through a launch one step at a time, as the person who started it: the server (made, or an existing one),
 * a website per app, an environment per app with its runtime, production settings, secrets, workers and resources,
 * the repository connected once per app (with its subdirectory, path filters and build cache, and a pull request with
 * the GitHub Actions workflow when it builds there), then the deploys, backends first and the rest together, each
 * built on GitHub Actions once its workflow is merged. While something is being set up it checks again a little later instead of
 * waiting, and each step records what it made, so trying again carries on where it stopped.
 */
final class RunLaunch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * How long, in seconds, to wait before checking on a server, website or deploy again.
     *
     * @var int
     */
    public const POLL_SECONDS = 20;

    /**
     * How long, in minutes, a launch may wait for its server, websites and deploys before giving up.
     *
     * @var int
     */
    public const MAX_MINUTES = 90;

    /**
     * One try per run; it schedules its own next look.
     *
     * @var int
     */
    public int $tries = 1;

    /**
     * Create a new RunLaunch instance.
     *
     * @param  int  $launchId
     */
    public function __construct(public readonly int $launchId) {}

    /**
     * Take the launch as far as it can go now.
     *
     * @return void
     */
    public function handle(): void
    {
        $launch = Launch::query()->with(['project.account', 'creator'])->find($this->launchId);
        if ($launch === null || ! $launch->active()) {
            return;
        }
        $actor = $launch->creator;
        if ($actor === null) {
            $this->stop($launch, __('The person who started the launch no longer has an account.'));

            return;
        }
        if ($launch->created_at !== null && $launch->created_at->lt(now()->subMinutes(self::MAX_MINUTES))) {
            $this->stop($launch, __('It took longer than :minutes minutes. Open the server or deploy it stopped at to see why, then try again.', ['minutes' => self::MAX_MINUTES]));

            return;
        }
        $launch->forceFill(['status' => 'running', 'error' => null])->save();
        try {
            foreach (array_keys(Launch::STEPS) as $step) {
                $launch->forceFill(['step' => $step])->save();
                $done = match ($step) {
                    'server' => $this->server($launch, $actor),
                    'websites' => $this->websites($launch, $actor),
                    'environments' => $this->environments($launch, $actor),
                    'repositories' => $this->repositories($launch, $actor),
                    default => $this->deploys($launch, $actor),
                };
                if (! $done) {
                    $launch->forceFill(['status' => 'waiting'])->save();
                    self::dispatch($launch->id)->delay(now()->addSeconds(self::POLL_SECONDS));

                    return;
                }
            }
            $launch->forceFill(['status' => 'done', 'step' => null, 'finished_at' => now()])->save();
        } catch (ValidationException $exception) {
            $this->stop($launch, (string) collect($exception->errors())->flatten()->first());
        } catch (AuthorizationException) {
            $this->stop($launch, __('You no longer have permission to set this up.'));
        } catch (RuntimeException $exception) {
            $this->stop($launch, $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);
            $this->stop($launch, __('Something went wrong setting it up. Try again; what’s done already is kept.'));
        }
    }

    /**
     * Get the server ready: create it when the plan asks for a new one, then wait until it's set up.
     *
     * @param  Launch  $launch
     * @param  User  $actor
     * @return bool whether it's ready
     */
    private function server(Launch $launch, User $actor): bool
    {
        $plan = $launch->plan['server'];
        $serverId = $launch->result['server_id'] ?? ($plan['mode'] === 'existing' ? (int) $plan['server_id'] : null);
        if ($serverId === null) {
            $provider = Provider::query()->where('account_id', $launch->project->account_id)->findOrFail((int) $plan['provider_id']);
            $server = app(CreateServer::class)->handle($launch->project->account, $actor, [
                'provider_id' => $provider->id, 'type' => ServerType::App->value, 'name' => Str::slug((string) $launch->plan['repository']['name']) ?: 'app',
                'region' => (string) $plan['region'], 'size' => (string) $plan['size'], 'image' => (string) $plan['image'],
            ]);
            $this->remember($launch, ['server_id' => $server->id]);

            return false;
        }
        $this->remember($launch, ['server_id' => $serverId]);
        $server = Server::query()->where('account_id', $launch->project->account_id)->findOrFail((int) $serverId);
        if ($server->provisioning_status === Server::STATUS_ACTIVE) {
            return true;
        }
        if (in_array($server->provisioning_status, [Server::STATUS_QUEUED, Server::STATUS_WAITING_FOR_IP, Server::STATUS_PROVISIONING], true)) {
            return false;
        }

        throw new RuntimeException(__('The server :server couldn’t be set up. Open it to see why.', ['server' => $server->label()]));
    }

    /**
     * Create a website per app on the server, then wait until they're all set up.
     *
     * @param  Launch  $launch
     * @param  User  $actor
     * @return bool
     */
    private function websites(Launch $launch, User $actor): bool
    {
        $ready = true;
        foreach ($launch->plan['apps'] as $index => $app) {
            $id = $launch->result['apps'][$index]['website_id'] ?? null;
            if ($id === null) {
                $website = app(CreateWebsite::class)->handle($launch->project->account, $actor, [
                    'name' => (string) $app['name'], 'server_id' => (int) ($launch->result['server_id'] ?? 0), 'url' => (string) $app['domain'],
                    'has_database' => (bool) $app['mysql'], 'health_check_enabled' => true, 'health_check_path' => (string) $app['healthPath'],
                ]);
                $this->rememberApp($launch, $index, ['website_id' => $website->id]);
                $ready = false;

                continue;
            }
            $website = Website::query()->findOrFail((int) $id);
            if ($website->provisioning_status === Website::STATUS_FAILED) {
                throw new RuntimeException(__('The website :website couldn’t be set up. Open it to see why.', ['website' => $website->url]));
            }
            $ready = $ready && $website->provisioning_status === Website::STATUS_ACTIVE;
        }

        return $ready;
    }

    /**
     * Give each app its environment: the project's Production for the first app when nothing deploys to it yet,
     * otherwise a new one; then its runtime, variables, secrets, workers and resources.
     *
     * @param  Launch  $launch
     * @param  User  $actor
     * @return bool
     */
    private function environments(Launch $launch, User $actor): bool
    {
        foreach ($launch->plan['apps'] as $index => $app) {
            if (isset($launch->result['apps'][$index]['environment_id'])) {
                continue;
            }
            $website = Website::query()->findOrFail((int) ($launch->result['apps'][$index]['website_id'] ?? 0));
            $environment = $this->environmentFor($launch, $actor, (string) $app['environment']);
            $this->runtime($actor, $environment, $app);
            $variables = (array) $app['variables'];
            if ($app['framework'] === 'laravel') {
                $variables['APP_KEY'] ??= 'base64:'.base64_encode(random_bytes(32));
                if (! $app['mysql']) {
                    // A SQLite file inside a release would be replaced by every deploy; shared storage keeps it.
                    $variables += ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => "/var/www/{$website->deployment_slug}/shared/storage/database.sqlite"];
                }
            }
            foreach ($variables as $key => $value) {
                app(SaveEnvironmentVariable::class)->handle($actor, $environment, ['key' => (string) $key, 'value' => (string) $value, 'is_secret' => $key === 'APP_KEY', 'scope' => 'runtime']);
            }
            foreach ((array) ($app['secretValues'] ?? []) as $key => $value) {
                if (filled($value)) {
                    app(SaveEnvironmentVariable::class)->handle($actor, $environment, ['key' => (string) $key, 'value' => (string) $value, 'is_secret' => true, 'scope' => 'runtime']);
                }
            }
            if ($app['queue'] && filled($app['workerCommand'])) {
                app(SaveEnvironmentProcess::class)->handle($actor, $environment, ['name' => 'queue', 'type' => 'worker', 'command' => (string) $app['workerCommand'], 'replicas' => 1, 'restart_policy' => 'always', 'restart_delay_seconds' => 5, 'is_enabled' => true]);
            }
            if ($app['scheduler']) {
                app(SaveEnvironmentProcess::class)->handle($actor, $environment, ['name' => 'scheduler', 'type' => 'scheduler', 'command' => 'php artisan schedule:work', 'replicas' => 1, 'restart_policy' => 'always', 'restart_delay_seconds' => 5, 'is_enabled' => true]);
            }
            if ($app['mysql']) {
                app(SaveEnvironmentResource::class)->handle($actor, $environment, ['name' => 'database', 'type' => 'mysql', 'is_managed' => true, 'variables' => null, 'database_website_id' => $website->id]);
            }
            if ($app['redis']) {
                app(SaveEnvironmentResource::class)->handle($actor, $environment, ['name' => 'redis', 'type' => 'redis', 'is_managed' => true, 'variables' => null]);
            }
            if ((bool) ($app['githubActions'] ?? false)) {
                app(SaveEnvironmentBuildServer::class)->handle($actor, $environment, null, null, true);
            }
            $website->forceFill(['environment_id' => $environment->id])->save();
            $this->rememberApp($launch, $index, ['environment_id' => $environment->id]);
        }

        return true;
    }

    /**
     * Connect the repository once per app, to its website and environment, then deploy-ready settings.
     *
     * @param  Launch  $launch
     * @param  User  $actor
     * @return bool
     */
    private function repositories(Launch $launch, User $actor): bool
    {
        $source = $launch->plan['repository'];
        foreach ($launch->plan['apps'] as $index => $app) {
            if (isset($launch->result['apps'][$index]['repository_id'])) {
                continue;
            }
            $repository = app(SaveRepository::class)->handle($actor, $launch->project, [
                'name' => (string) $app['name'], 'provider_id' => (int) $source['provider_id'], 'url' => (string) $source['url'], 'branch' => (string) $source['branch'],
                'website_id' => (int) ($launch->result['apps'][$index]['website_id'] ?? 0), 'environment_id' => (string) ($launch->result['apps'][$index]['environment_id'] ?? ''),
                'deployment_root' => $app['path'] !== '' ? (string) $app['path'] : null,
                'build_commands' => $app['runtime'] === 'php' ? ($app['buildCommand'] ?? null) : null,
                'auto_deploy_include_paths' => array_values(array_map(strval(...), (array) $app['includePaths'])),
            ]);
            app(UpdateBuildCache::class)->handle($actor, $repository, true);
            $this->rememberApp($launch, $index, ['repository_id' => $repository->id]);
            if ((bool) ($app['githubActions'] ?? false)) {
                // The build needs the workflow in the repository; a pull request adds it for someone to merge.
                try {
                    $this->rememberApp($launch, $index, ['workflow' => app(GitHubWorkflowPullRequests::class)->open($repository->load(['provider', 'website', 'environment']))]);
                } catch (RuntimeException $exception) {
                    $this->rememberApp($launch, $index, ['workflowError' => $exception->getMessage()]);
                }
            }
        }

        return true;
    }

    /**
     * Deploy the backends first, together, and the rest together once they're live; done when every deploy is.
     *
     * @param  Launch  $launch
     * @param  User  $actor
     * @return bool
     */
    private function deploys(Launch $launch, User $actor): bool
    {
        foreach ([true, false] as $backends) {
            $group = array_filter($launch->plan['apps'], fn (array $app): bool => (bool) $app['backend'] === $backends);
            $live = true;
            foreach (array_keys($group) as $index) {
                $buildId = $launch->result['apps'][$index]['build_id'] ?? null;
                if ($buildId === null) {
                    $repository = Repository::query()->with(['provider', 'website', 'environment'])->findOrFail((int) ($launch->result['apps'][$index]['repository_id'] ?? 0));
                    // Built on GitHub Actions, it waits for the workflow pull request to be merged.
                    if ((bool) ($launch->plan['apps'][$index]['githubActions'] ?? false) && app(GitHubWorkflowPullRequests::class)->status($repository, true) !== 'current') {
                        $this->rememberApp($launch, $index, ['waitingForWorkflow' => true]);
                        $live = false;

                        continue;
                    }
                    $this->rememberApp($launch, $index, ['waitingForWorkflow' => false]);
                    $build = app(DeployRepository::class)->handle($actor, $repository, null, 'manual');
                    $this->rememberApp($launch, $index, ['build_id' => $build->id]);
                    $live = false;

                    continue;
                }
                $build = Build::query()->findOrFail((int) $buildId);
                if (in_array($build->status, [Build::STATUS_FAILED, Build::STATUS_CANCELED, Build::STATUS_REJECTED], true)) {
                    throw new RuntimeException(__('The deploy of :app didn’t finish (:status). Open it to see why, then try again.', ['app' => $launch->plan['apps'][$index]['name'], 'status' => $build->status]));
                }
                $live = $live && $build->status === Build::STATUS_SUCCEEDED;
            }
            if (! $live) {
                return false;
            }
        }

        return true;
    }

    /**
     * Use the project's Production for the first app when nothing deploys to it yet; otherwise create the one named,
     * numbering it when the name's taken.
     *
     * @param  Launch  $launch
     * @param  User  $actor
     * @param  string  $name
     * @return Environment
     */
    private function environmentFor(Launch $launch, User $actor, string $name): Environment
    {
        $project = $launch->project;
        $existing = $project->environments()->where('slug', Str::slug($name))->first();
        if ($existing !== null && ! Repository::query()->where('environment_id', $existing->id)->exists()) {
            return $existing;
        }
        $candidate = $name;
        for ($n = 2; $project->environments()->where('slug', Str::slug($candidate))->exists(); $n++) {
            $candidate = "{$name}-{$n}";
        }

        return app(CreateEnvironment::class)->handle($actor, $project, $candidate, EnvironmentKind::Production);
    }

    /**
     * Set the environment's runtime and how the app builds and starts, keeping its other deploy settings.
     *
     * @param  User  $actor
     * @param  Environment  $environment
     * @param  array<string, mixed>  $app
     * @return void
     */
    private function runtime(User $actor, Environment $environment, array $app): void
    {
        $node = $app['runtime'] !== 'php';
        app(UpdateEnvironmentDeploySettings::class)->handle($actor, $environment, [
            'requires_deployment_approval' => (bool) $environment->requires_deployment_approval, 'migration_safety' => (bool) $environment->migration_safety,
            'deployment_strategy' => (string) ($environment->deployment_strategy ?: 'blue_green'), 'rolling_pause_seconds' => (int) ($environment->rolling_pause_seconds ?? 2),
            'automatic_rollback' => (bool) $environment->automatic_rollback, 'post_deployment_observation_minutes' => $environment->post_deployment_observation_minutes,
            'rollback_error_rate_percent' => $environment->rollback_error_rate_percent, 'rollback_latency_percent' => $environment->rollback_latency_percent,
            'rollback_conversion_drop_percent' => $environment->rollback_conversion_drop_percent,
            'runtime_type' => (string) $app['runtime'], 'runtime_version' => $app['runtimeVersion'] ?? null,
            'build_command' => $node ? ($app['buildCommand'] ?? null) : null, 'start_command' => $node ? ($app['startCommand'] ?? null) : null,
            'container_port' => $node ? ($app['port'] ?? null) : null, 'dockerfile_path' => $environment->dockerfile_path, 'compose_service' => $environment->compose_service,
            'minimum_replicas' => (int) $environment->minimum_replicas, 'maximum_replicas' => (int) $environment->maximum_replicas, 'desired_replicas' => (int) $environment->desired_replicas,
            'autoscale_enabled' => (bool) $environment->autoscale_enabled, 'autoscale_cpu_target' => (int) $environment->autoscale_cpu_target, 'autoscale_queue_jobs' => $environment->autoscale_queue_jobs,
        ]);
    }

    /**
     * Keep something the launch made.
     *
     * @param  Launch  $launch
     * @param  array<string, mixed>  $values
     * @return void
     */
    private function remember(Launch $launch, array $values): void
    {
        $launch->forceFill(['result' => [...($launch->result ?? []), ...$values]])->save();
    }

    /**
     * Keep something the launch made for one app.
     *
     * @param  Launch  $launch
     * @param  int  $index
     * @param  array<string, mixed>  $values
     * @return void
     */
    private function rememberApp(Launch $launch, int $index, array $values): void
    {
        $result = $launch->result ?? [];
        $result['apps'][$index] = [...($result['apps'][$index] ?? []), ...$values];
        $launch->forceFill(['result' => $result])->save();
    }

    /**
     * Stop with a reason people can act on.
     *
     * @param  Launch  $launch
     * @param  string  $error
     * @return void
     */
    private function stop(Launch $launch, string $error): void
    {
        $launch->forceFill(['status' => 'failed', 'error' => mb_substr($error, 0, 1000)])->save();
    }
}
