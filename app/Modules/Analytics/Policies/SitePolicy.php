<?php

namespace App\Modules\Analytics\Policies;

use App\Modules\Analytics\Enums\WorkspaceRole;
use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Models\Workspace;
use App\Modules\Analytics\Services\AnalyticsWorkspaceAccess;
use Illuminate\Contracts\Auth\Authenticatable;

class SitePolicy
{
    public function __construct(private readonly AnalyticsWorkspaceAccess $access) {}

    public function view(Authenticatable $user, Site $site): bool
    {
        $role = $this->access->roleFor($user, $site->workspace);

        return ($role?->canManageSites() === true || $role === WorkspaceRole::Viewer)
            && $this->access->hasSiteAccess($user, $site);
    }

    public function manage(Authenticatable $user, Site $site): bool
    {
        return $this->access->roleFor($user, $site->workspace)?->canManageSites() === true
            && $this->access->hasSiteAccess($user, $site);
    }

    public function delete(Authenticatable $user, Site $site): bool
    {
        return $this->access->roleFor($user, $site->workspace) === WorkspaceRole::Owner
            && $this->access->hasSiteAccess($user, $site);
    }

    public function create(Authenticatable $user, Workspace $workspace): bool
    {
        return $this->access->roleFor($user, $workspace)?->canManageSites() === true;
    }
}
