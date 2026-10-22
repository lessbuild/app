<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Models\Launch;
use App\Models\Project;
use App\Models\Provider;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowLaunchFormController
{
    /**
     * The Launch from a repository page: the account's Git providers to pick a repository from, its cloud providers
     * for a new server, and the project's recent launches.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview): JsonResponse
    {
        $providers = Provider::query()->where('account_id', $project->account_id)->orderBy('name')->get();

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'sources' => $providers->filter(fn (Provider $provider): bool => $provider->type->isSourceControl())
                ->map(fn (Provider $provider): array => ['value' => (string) $provider->id, 'label' => $provider->name.' ('.$provider->type->label().')'])->values(),
            'clouds' => $providers->filter(fn (Provider $provider): bool => $provider->type->hostsServers())
                ->map(fn (Provider $provider): array => ['value' => (string) $provider->id, 'label' => $provider->name.' ('.$provider->type->label().')'])->values(),
            'launches' => $project->launches()->latest('id')->limit(5)->get()->map(fn (Launch $launch): array => [
                'id' => $launch->id, 'repository' => (string) ($launch->plan['repository']['url'] ?? ''), 'status' => $launch->status, 'createdAt' => $launch->created_at?->toIso8601String(),
            ]),
        ]);
    }
}
