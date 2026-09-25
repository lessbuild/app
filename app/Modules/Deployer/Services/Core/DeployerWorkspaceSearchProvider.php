<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Contracts\WorkspaceSearchProvider;
use App\Core\Data\Search\WorkspaceSearchResult;
use App\Core\Exceptions\Search\WorkspaceSearchProviderUnavailable;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Services\LegacyIdentityResolver;
use App\Core\Services\Search\WorkspaceSearchPattern;
use App\Modules\Deployer\Models\Build;
use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\Server;
use Illuminate\Support\Facades\Route;

final class DeployerWorkspaceSearchProvider implements WorkspaceSearchProvider
{
    public function __construct(private readonly LegacyIdentityResolver $identities) {}

    public function search(PlatformUser $user, Workspace $workspace, string $query): array
    {
        if (! Route::has('servers.show') || ! Route::has('builds.show')) {
            throw new WorkspaceSearchProviderUnavailable('Deployer search destinations are unavailable.');
        }

        $sourceUserIds = $this->identities->sourceIdsFor($user, 'deployer');
        $sourceOrganizationIds = $this->identities->sourceIdsForCanonical(
            'deployer',
            'organization',
            $workspace->getKey(),
            'workspace',
        );

        if ($sourceUserIds === [] || $sourceOrganizationIds === []) {
            return [];
        }

        $organizationIds = Organization::query()
            ->whereKey($sourceOrganizationIds)
            ->where(fn ($organizations) => $organizations
                ->whereIn('owner_id', $sourceUserIds)
                ->orWhereHas('members', fn ($members) => $members->whereIn('users.id', $sourceUserIds)))
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id);

        if ($organizationIds->isEmpty()) {
            return [];
        }

        $pattern = WorkspaceSearchPattern::contains($query);
        $servers = Server::query()
            ->whereIn('organization_id', $organizationIds)
            ->where(fn ($servers) => $servers
                ->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("display_name LIKE ? ESCAPE '!'", [$pattern]))
            ->orderByRaw('COALESCE(display_name, name)')
            ->limit(5)
            ->get(['id', 'organization_id', 'name', 'display_name', 'provisioning_status'])
            ->map(fn (Server $server): WorkspaceSearchResult => new WorkspaceSearchResult(
                type: __('Server'),
                title: $server->display_name ?: ($server->name ?: __('Server #:id', ['id' => $server->getKey()])),
                subtitle: str((string) $server->provisioning_status)->replace('_', ' ')->title()->toString(),
                url: route('servers.show', [
                    'server' => $server->getKey(),
                    'organization_id' => $server->organization_id,
                ]),
            ));

        $builds = Build::query()
            ->where(function ($builds) use ($organizationIds): void {
                $builds->whereHas('repository', fn ($repository) => $repository->whereIn('organization_id', $organizationIds))
                    ->orWhereHas('environment.project', fn ($project) => $project->whereIn('organization_id', $organizationIds));
            })
            ->where(function ($builds) use ($organizationIds): void {
                $builds->whereNull('builds.repository_id')
                    ->orWhereHas('repository', fn ($repository) => $repository->whereIn('organization_id', $organizationIds));
            })
            ->where(function ($builds) use ($organizationIds): void {
                $builds->whereNull('builds.environment_id')
                    ->orWhereHas('environment.project', fn ($project) => $project->whereIn('organization_id', $organizationIds));
            })
            ->where(function ($builds): void {
                $builds->whereNull('builds.repository_id')
                    ->orWhereNull('builds.environment_id')
                    ->orWhereExists(function ($query): void {
                        $query->selectRaw('1')
                            ->from('repositories')
                            ->join('environments', 'environments.id', '=', 'builds.environment_id')
                            ->join('projects', 'projects.id', '=', 'environments.project_id')
                            ->whereColumn('repositories.id', 'builds.repository_id')
                            ->whereColumn('repositories.organization_id', 'projects.organization_id')
                            ->whereNull('repositories.deleted_at');
                    });
            })
            ->where(function ($builds) use ($pattern): void {
                $builds->whereRaw("builds.revision LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("builds.status LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereHas('repository', fn ($repository) => $repository->whereRaw("name LIKE ? ESCAPE '!'", [$pattern]));
            })
            ->with([
                'repository:id,organization_id,name',
                'environment.project:id,organization_id',
            ])
            ->latest('builds.id')
            ->limit(5)
            ->get(['builds.id', 'builds.repository_id', 'builds.environment_id', 'builds.status', 'builds.revision'])
            ->map(function (Build $build) use ($organizationIds): ?WorkspaceSearchResult {
                $sourceOrganizationIds = collect([
                    $build->repository?->organization_id,
                    $build->environment?->project?->organization_id,
                ])
                    ->filter(fn ($id): bool => $id !== null && (string) $id !== '')
                    ->map(static fn ($id): string => (string) $id)
                    ->unique()
                    ->values();

                if ($sourceOrganizationIds->count() !== 1
                    || ! $organizationIds->contains((string) $sourceOrganizationIds->first())) {
                    return null;
                }

                $organizationId = (string) $sourceOrganizationIds->first();

                return new WorkspaceSearchResult(
                    type: __('Deployment'),
                    title: __('Build #:id', ['id' => $build->getKey()]),
                    subtitle: collect([
                        $build->repository?->name,
                        str((string) $build->status)->replace('_', ' ')->title()->toString(),
                        $build->shortRevision(),
                    ])->filter()->implode(' · '),
                    url: route('builds.show', [
                        'build' => $build->getKey(),
                        'organization_id' => $organizationId,
                    ]),
                );
            })
            ->filter()
            ->values();

        return $servers->concat($builds)->values()->all();
    }
}
