<?php

namespace App\Modules\Analytics\Services\Core;

use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Core\Services\LegacyIdentityResolver;
use App\Modules\Analytics\Enums\WorkspaceRole;
use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Models\Workspace;
use App\Modules\Analytics\Policies\SitePolicy;
use App\Modules\Analytics\Services\AnalyticsWorkspaceAccess;
use Illuminate\Database\Eloquent\Builder;

/** Resolves only an existing one-to-one Core-to-Analytics workspace mapping. */
final class AnalyticsCoreAdministrationAccess
{
    public function __construct(
        private readonly LegacyIdentityResolver $identities,
        private readonly AnalyticsWorkspaceAccess $access,
    ) {}

    public function workspace(PlatformUser $user, CoreWorkspace $coreWorkspace): Workspace
    {
        $sourceIds = $this->identities->sourceIdsForCanonical(
            'analytics', 'workspace', (string) $coreWorkspace->getKey(), 'workspace',
        );
        abort_unless(count($sourceIds) === 1, 404);

        $workspace = Workspace::query()->find($sourceIds[0]);
        abort_unless($workspace !== null && $this->access->hasAccess($user, $workspace), 404);

        return $workspace;
    }

    public function role(PlatformUser $user, Workspace $workspace): ?WorkspaceRole
    {
        return $this->access->roleFor($user, $workspace);
    }

    public function site(PlatformUser $user, Workspace $workspace, string $siteId, bool $manage = false): Site
    {
        $role = $this->role($user, $workspace);
        abort_unless($manage ? $role?->canManageSites() === true : $role !== null, 403);

        $site = $this->access->sitesQuery($user, $workspace)
            ->whereKey($siteId)
            ->firstOrFail();
        abort_unless($manage
            ? app(SitePolicy::class)->manage($user, $site)
            : app(SitePolicy::class)->view($user, $site), 404);

        return $site;
    }

    /** @return Builder<Site> */
    public function sites(PlatformUser $user, Workspace $workspace): Builder
    {
        return $this->access->sitesQuery($user, $workspace);
    }
}
