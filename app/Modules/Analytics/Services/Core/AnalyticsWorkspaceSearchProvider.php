<?php

namespace App\Modules\Analytics\Services\Core;

use App\Core\Contracts\WorkspaceSearchProvider;
use App\Core\Data\Search\WorkspaceSearchResult;
use App\Core\Exceptions\Search\WorkspaceSearchProviderUnavailable;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Services\LegacyIdentityResolver;
use App\Core\Services\Search\WorkspaceSearchPattern;
use App\Modules\Analytics\Models\Site;
use Illuminate\Support\Facades\Route;

final class AnalyticsWorkspaceSearchProvider implements WorkspaceSearchProvider
{
    public function __construct(private readonly LegacyIdentityResolver $identities) {}

    public function search(PlatformUser $user, Workspace $workspace, string $query): array
    {
        if (! Route::has('analytics.dashboard')) {
            throw new WorkspaceSearchProviderUnavailable('Analytics site search is unavailable.');
        }

        $sourceUserIds = $this->identities->sourceIdsFor($user, 'analytics');
        $sourceWorkspaceIds = $this->identities->sourceIdsForCanonical(
            'analytics',
            'workspace',
            $workspace->getKey(),
            'workspace',
        );

        if ($sourceUserIds === [] || $sourceWorkspaceIds === []) {
            return [];
        }

        $pattern = WorkspaceSearchPattern::contains($query);

        return Site::query()
            ->whereIn('workspace_id', $sourceWorkspaceIds)
            ->whereHas('workspace.users', fn ($users) => $users->whereIn('users.id', $sourceUserIds))
            ->where(fn ($sites) => $sites
                ->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("slug LIKE ? ESCAPE '!'", [$pattern]))
            ->orderBy('name')
            ->limit(5)
            ->get(['id', 'name', 'domains'])
            ->map(fn (Site $site): WorkspaceSearchResult => new WorkspaceSearchResult(
                type: __('Analytics site'),
                title: $site->name,
                subtitle: is_array($site->domains) ? ($site->domains[0] ?? null) : null,
                url: route('analytics.dashboard', ['site' => $site->getKey()]),
            ))
            ->all();
    }
}
