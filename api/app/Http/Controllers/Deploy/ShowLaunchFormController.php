<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Models\Launch;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Repository;
use App\Models\User;
use App\Models\Website;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Projects\ProjectResources;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowLaunchFormController
{
    /**
     * The Launch from a repository page: the account's Git providers to pick a repository from, its cloud providers
     * for a new server, the project's websites an app can go onto (with their domains), and its recent launches.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview): JsonResponse
    {
        $providers = Provider::query()->where('account_id', $project->account_id)->orderBy('name')->get();
        $connected = Repository::query()->whereNotNull('website_id')->pluck('website_id')->map(intval(...))->all();

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'sources' => $providers->filter(fn (Provider $provider): bool => $provider->type->isSourceControl())
                ->map(fn (Provider $provider): array => ['value' => (string) $provider->id, 'label' => $provider->name.' ('.$provider->type->label().')'])->values(),
            'clouds' => $providers->filter(fn (Provider $provider): bool => $provider->type->hostsServers())
                ->map(fn (Provider $provider): array => ['value' => (string) $provider->id, 'label' => $provider->name.' ('.$provider->type->label().')'])->values(),
            'websites' => Website::query()->where('account_id', $project->account_id)
                ->whereIn('id', app(ProjectResources::class)->visibleWebsiteIds($project))
                ->whereNotIn('id', $connected)->with(['server', 'environment.project'])->orderBy('url')->get()
                ->filter(fn (Website $website): bool => $user->can('view', $website))
                ->map(fn (Website $website): array => [
                    'value' => (string) $website->id, 'label' => $website->name.' — '.$website->url, 'domain' => $website->url,
                    'server' => $website->server?->label(),
                    'environment' => $website->environment !== null ? $website->environment->project->name.' · '.$website->environment->name : null,
                ])->values(),
            'launches' => $project->launches()->latest('id')->limit(5)->get()->map(fn (Launch $launch): array => [
                'id' => $launch->id, 'repository' => (string) ($launch->plan['repository']['url'] ?? ''), 'status' => $launch->status, 'createdAt' => $launch->created_at?->toIso8601String(),
            ]),
        ]);
    }
}
