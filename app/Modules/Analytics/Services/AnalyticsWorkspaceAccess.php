<?php

namespace App\Modules\Analytics\Services;

use App\Core\Models\PlatformUser;
use App\Core\Services\Auth\ProductAuthentication;
use App\Core\Services\Identity\ProductWorkspaceAccess;
use App\Core\Services\LegacyIdentityResolver;
use App\Modules\Analytics\Enums\WorkspaceRole;
use App\Modules\Analytics\Models\User as AnalyticsUser;
use App\Modules\Analytics\Models\Workspace;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

final class AnalyticsWorkspaceAccess
{
    public function __construct(
        private readonly LegacyIdentityResolver $identities,
        private readonly ProductAuthentication $authentication,
        private readonly ProductWorkspaceAccess $productWorkspaceAccess,
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
            ->with(['sites' => fn (HasMany $query) => $query->orderBy('name')])
            ->orderBy('name')
            ->get()
            ->filter(fn (Workspace $workspace): bool => $this->hasAccess($user, $workspace))
            ->values();
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

        $platformUser = $user instanceof PlatformUser
            ? $user
            : PlatformUser::query()->find(data_get($user, 'platform_user_id'));

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
