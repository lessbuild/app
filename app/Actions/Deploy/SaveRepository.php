<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\Environment;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SaveRepository
{
    /**
     * Connect a repository to a website in the project's account, or change it.
     *
     * @param  User  $actor
     * @param  Project  $project
     * @param  array{name: string, provider_id: int|string, url: string, branch: string, website_id: int|string, environment_id?: string|null, deployment_root?: string|null, build_commands?: string|null, post_deployment_commands?: string|null, auto_deploy_include_paths?: list<string>, auto_deploy_exclude_paths?: list<string>}  $data  validated by RepositoryRequest
     * @param  Repository|null  $repository
     * @return Repository
     */
    public function handle(User $actor, Project $project, array $data, ?Repository $repository = null): Repository
    {
        Gate::forUser($actor)->authorize($repository === null ? 'create' : 'update', $repository ?? [Repository::class, $project]);
        $provider = Provider::query()->where('account_id', $project->account_id)->find((int) $data['provider_id']);
        if ($provider === null || ! $provider->type->isSourceControl()) {
            throw ValidationException::withMessages(['provider_id' => __('Choose a GitHub, GitLab or Bitbucket provider from this account.')]);
        }
        if (! $provider->supportsRepositoryUrl($data['url'])) {
            throw ValidationException::withMessages(['url' => __('Use a :host repository address for this provider.', ['host' => $provider->type->repositoryHost()])]);
        }
        $website = Website::query()->where('account_id', $project->account_id)->find((int) $data['website_id']);
        if ($website === null) {
            throw ValidationException::withMessages(['website_id' => __('Choose a website in this account.')]);
        }
        $environment = filled($data['environment_id'] ?? null) ? Environment::query()->where('project_id', $project->id)->find($data['environment_id']) : null;
        if (filled($data['environment_id'] ?? null) && $environment === null) {
            throw ValidationException::withMessages(['environment_id' => __('Choose one of this project’s environments.')]);
        }
        $repository ??= new Repository;
        // An App installation's pushes arrive signed with the App's secret; a switch away from the App turns that off.
        $webhook = $provider->isGitHubApp()
            ? ['webhook_enabled' => true, 'webhook_secret' => (string) config('github-app.webhook_secret')]
            : ($repository->provider?->isGitHubApp() === true ? ['webhook_enabled' => false, 'webhook_secret' => null] : []);
        $repository->forceFill([
            'project_id' => $project->id, 'created_by' => $repository->created_by ?? $actor->id, 'provider_id' => $provider->id,
            'website_id' => $website->id, 'environment_id' => $environment?->id, 'name' => trim($data['name']), 'url' => $data['url'],
            'branch' => $data['branch'], 'deployment_root' => $data['deployment_root'] ?? null,
            'build_commands' => $data['build_commands'] ?? null, 'post_deployment_commands' => $data['post_deployment_commands'] ?? null,
            'auto_deploy_include_paths' => ($data['auto_deploy_include_paths'] ?? []) ?: null, 'auto_deploy_exclude_paths' => ($data['auto_deploy_exclude_paths'] ?? []) ?: null,
            ...$webhook,
        ])->save();

        return $repository;
    }
}
