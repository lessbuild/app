<?php

namespace App\Modules\Analytics\Services;

use App\Core\Models\PlatformUser;
use App\Core\Services\Auth\ProductAuthentication;
use App\Core\Services\Identity\MappedProjectResourceAccess;
use App\Core\Services\Identity\ProductWorkspaceAccess;
use App\Core\Services\Identity\ResolvePlatformUser;
use App\Core\Services\LegacyIdentityResolver;
use App\Modules\Analytics\Enums\WorkspaceRole;
use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Models\User as AnalyticsUser;
use App\Modules\Analytics\Models\Workspace;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class AnalyticsWorkspaceAccess
{
    public function __construct(
        private readonly LegacyIdentityResolver $identities,
        private readonly ProductAuthentication $authentication,
        private readonly ProductWorkspaceAccess $productWorkspaceAccess,
        private readonly MappedProjectResourceAccess $projectResources,
        private readonly ResolvePlatformUser $platformUsers,
    ) {}

    /** @return list<string> */
    public function productUserIds(Authenticatable $user): array
    {
        $mappedIds = $this->identities->sourceIdsFor($user, 'analytics');

        if ($user instanceof AnalyticsUser) {
            $mappedIds[] = (string) $user->getAuthIdentifier();
        }

        return array_values(array_unique($mappedIds));
    }

    /** @return Collection<int, Workspace> */
    public function workspacesFor(Authenticatable $user): Collection
    {
        $productUserIds = $this->productUserIds($user);

        if ($productUserIds === []) {
            return collect();
        }

        return Workspace::query()
            ->whereHas('users', fn (Builder $query) => $query->whereIn('users.id', $productUserIds))
            ->orderBy('name')
            ->get()
            ->filter(fn (Workspace $workspace): bool => $this->hasAccess($user, $workspace))
            ->each(fn (Workspace $workspace) => $workspace->setRelation(
                'sites',
                $this->sitesQuery($user, $workspace)->orderBy('name')->get(),
            ))
            ->values();
    }

    public function hasSiteAccess(Authenticatable $user, Site $site): bool
    {
        $workspace = $site->workspace;

        return $workspace instanceof Workspace
            && $this->hasAccess($user, $workspace)
            && $this->projectResources->allows(
                $user, 'analytics', 'site', $site->getKey(), 'workspace', $workspace->getKey(),
            );
    }

    /** @return Builder<Site> */
    public function sitesQuery(Authenticatable $user, Workspace $workspace, bool $withTrashed = false): Builder
    {
        $query = Site::query()->where('workspace_id', $workspace->getKey());

        if ($withTrashed) {
            $query->withTrashed();
        }

        if (! $this->hasAccess($user, $workspace)) {
            return $query->whereRaw('1 = 0');
        }

        $deniedIds = $this->projectResources->deniedResourceIds(
            $user, 'analytics', 'site', 'workspace', $workspace->getKey(),
            (clone $query)->pluck('id')->all(),
        );

        return $deniedIds === null
            ? $query->whereRaw('1 = 0')
            : $query->when($deniedIds !== [], fn (Builder $sites) => $sites->whereNotIn('id', $deniedIds));
    }

    public function hasAccess(Authenticatable $user, Workspace $workspace): bool
    {
        $productUserIds = $this->productUserIds($user);

        if ($productUserIds === [] || ! $workspace->users()->whereIn('users.id', $productUserIds)->exists()) {
            return false;
        }

        if (! $this->authentication->usesCoreAuthority('analytics')) {
            return true;
        }

        $platformUser = $this->platformUsers->resolve($user, 'analytics');

        return $platformUser instanceof PlatformUser
            && $this->productWorkspaceAccess->allows($platformUser, 'analytics', 'workspace', $workspace->getKey());
    }

    public function roleFor(Authenticatable $user, Workspace $workspace): ?WorkspaceRole
    {
        if (! $this->hasAccess($user, $workspace)) {
            return null;
        }

        $productUserIds = $this->productUserIds($user);

        if ($productUserIds === []) {
            return null;
        }

        return $this->roleForProductUserIds($workspace, $productUserIds);
    }

    /** @param list<string|int> $productUserIds */
    public function roleForProductUserIds(Workspace $workspace, array $productUserIds): ?WorkspaceRole
    {
        if ($productUserIds === []) {
            return null;
        }

        $roles = $workspace->users()
            ->whereIn('users.id', $productUserIds)
            ->get(['users.id'])
            ->map(fn ($productUser): ?WorkspaceRole => WorkspaceRole::tryFrom((string) $productUser->pivot->role))
            ->filter();

        // Multiple source identities can map to one confirmed Core account.
        // Resolve duplicate historical memberships to the strongest preserved role.
        return $roles->sortByDesc(fn (WorkspaceRole $role): int => match ($role) {
            WorkspaceRole::Owner => 3,
            WorkspaceRole::Admin => 2,
            WorkspaceRole::Viewer => 1,
        })->first();
    }
}
