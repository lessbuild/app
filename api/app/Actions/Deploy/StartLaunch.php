<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Enums\ProviderType;
use App\Enums\ServerType;
use App\Jobs\Deploy\RunLaunch;
use App\Models\Launch;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Services\Deploy\LaunchPlanner;
use App\Services\Projects\ProjectResources;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class StartLaunch
{
    /**
     * Create a new StartLaunch instance.
     *
     * Turns a confirmed Launch page into a launch and starts it.
     *
     * @param  LaunchPlanner  $planner  Proposes the plan again from the repository, so only the choices come from the page.
     */
    public function __construct(private readonly LaunchPlanner $planner) {}

    /**
     * Plan the repository again, take the person's choices for each app (name, domain, environment, database, Redis,
     * workers, secrets, GitHub Actions) and the server, check them, and start the launch.
     *
     * @param  User  $actor
     * @param  Project  $project
     * @param  array{provider_id: int|string, url: string, branch: string, domain: string, server: array<string, mixed>, apps: array<int, array<string, mixed>>}  $data
     * @return Launch
     */
    public function handle(User $actor, Project $project, array $data): Launch
    {
        Gate::forUser($actor)->authorize('create', [Repository::class, $project]);
        Gate::forUser($actor)->authorize('create', [Website::class, $project->account]);
        $provider = Provider::query()->where('account_id', $project->account_id)->find((int) $data['provider_id']);
        if ($provider === null || ! $provider->type->isSourceControl()) {
            throw ValidationException::withMessages(['provider_id' => __('Choose a GitHub, GitLab or Bitbucket provider from this account.')]);
        }
        $server = $this->server($actor, $project, (array) $data['server']);
        $plan = $this->planner->plan($project, $provider, (string) $data['url'], (string) $data['branch'], (string) $data['domain']);
        $apps = [];
        $websiteIds = [];
        foreach ($plan['apps'] as $index => $app) {
            $choice = (array) ($data['apps'][$index] ?? []);
            if (! filled($choice['website_id'] ?? null)) {
                throw ValidationException::withMessages(["apps.{$index}.website_id" => __('Choose a website for this app. Add one under Infrastructure → Websites first.')]);
            }
            $website = $this->existingWebsite($actor, $project, (int) $choice['website_id'], $index, $websiteIds);
            $websiteIds[] = $website->id;
            $domain = $website->url;
            $secrets = array_intersect_key(array_map(fn (mixed $value): string => trim((string) $value), (array) ($choice['secretValues'] ?? [])), array_flip($app['secrets']));
            $apps[] = [
                ...$app,
                'name' => mb_substr(trim((string) ($choice['name'] ?? $app['name'])) ?: (string) $app['name'], 0, 255),
                'domain' => $domain,
                'websiteId' => $website->id,
                'environment' => mb_substr(trim((string) ($choice['environment'] ?? $app['environment'])) ?: (string) $app['environment'], 0, 60),
                'mysql' => $app['canUseMysql'] && (bool) ($choice['mysql'] ?? $app['mysql']),
                'redis' => (bool) ($choice['redis'] ?? $app['redis']),
                'queue' => $app['workerCommand'] !== null && (bool) ($choice['queue'] ?? $app['queue']),
                'scheduler' => $app['framework'] === 'laravel' && (bool) ($choice['scheduler'] ?? $app['scheduler']),
                'githubActions' => $provider->type === ProviderType::GitHub && in_array($app['runtime'], ['php', 'node'], true) && (bool) ($choice['githubActions'] ?? false),
                'variables' => [...$app['variables'], 'APP_URL' => 'https://'.$domain],
                'secretValues' => $secrets,
            ];
            if ($app['framework'] !== 'laravel') {
                unset($apps[$index]['variables']['APP_URL']);
            }
        }
        $launch = new Launch;
        $launch->forceFill([
            'project_id' => $project->id, 'created_by' => $actor->id, 'status' => 'queued', 'step' => 'server',
            'plan' => ['repository' => $plan['repository'], 'server' => $server, 'apps' => $apps],
        ])->save();
        RunLaunch::dispatch($launch->id);

        return $launch;
    }

    /**
     * Resolve and check an existing website an app is going onto: it's one of the project's, the person may use it,
     * no earlier app in this launch chose it, and no repository deploys to it yet.
     *
     * @param  User  $actor
     * @param  Project  $project
     * @param  int  $websiteId
     * @param  int  $index  The app's place, for the error field.
     * @param  list<int>  $chosen  Website ids earlier apps already took.
     * @return Website
     */
    private function existingWebsite(User $actor, Project $project, int $websiteId, int $index, array $chosen): Website
    {
        $field = "apps.{$index}.website_id";
        $website = Website::query()->where('account_id', $project->account_id)->find($websiteId);
        if ($website === null || ! app(ProjectResources::class)->mayUseWebsite($actor, $website, $project->id)) {
            throw ValidationException::withMessages([$field => __('Choose one of this project’s websites.')]);
        }
        if (in_array($website->id, $chosen, true)) {
            throw ValidationException::withMessages([$field => __('Each app needs its own website.')]);
        }
        if (Repository::query()->where('website_id', $website->id)->exists()) {
            throw ValidationException::withMessages([$field => __(':website already has a repository deploying to it.', ['website' => $website->url])]);
        }

        return $website;
    }

    /**
     * Check the server choice: one of the account's active app servers (one of the person's projects', when they're
     * limited to some), or a new one from a cloud provider.
     *
     * @param  User  $actor
     * @param  Project  $project
     * @param  array<string, mixed>  $choice
     * @return array<string, mixed>
     */
    private function server(User $actor, Project $project, array $choice): array
    {
        if (($choice['mode'] ?? null) === 'existing') {
            $server = Server::query()->where('account_id', $project->account_id)->where('type', ServerType::App)->where('provisioning_status', Server::STATUS_ACTIVE)->find((int) ($choice['server_id'] ?? 0));

            $allowed = $server !== null && app(ProjectResources::class)->mayUseServer($actor, $server, $project->id);

            return $allowed ? ['mode' => 'existing', 'server_id' => $server->id] : throw ValidationException::withMessages(['server.server_id' => __('Choose one of your active app servers.')]);
        }
        $provider = Provider::query()->where('account_id', $project->account_id)->find((int) ($choice['provider_id'] ?? 0));
        if ($provider === null || ! $provider->type->hostsServers()) {
            throw ValidationException::withMessages(['server.provider_id' => __('Choose a cloud provider for the new server.')]);
        }
        foreach (['region', 'size', 'image'] as $key) {
            if (! is_string($choice[$key] ?? null) || trim($choice[$key]) === '' || strlen($choice[$key]) > 100) {
                throw ValidationException::withMessages(["server.{$key}" => __('Choose the new server’s region, size and image.')]);
            }
        }

        return ['mode' => 'new', 'provider_id' => $provider->id, 'region' => trim($choice['region']), 'size' => trim($choice['size']), 'image' => trim($choice['image'])];
    }
}
