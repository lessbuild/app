<?php

namespace App\Core\Contracts;

use App\Core\Data\Notifications\WorkspaceNativeNotificationSnapshot;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\Workspace;
use Illuminate\Support\Collection;

/** A bounded, recipient-authorized projection of a product's native inbox. */
interface WorkspaceNativeNotificationProvider
{
    /**
     * @param  Collection<int, Project>  $projects  Current active, membership-visible Core projects.
     * @param  list<string>  $products  Current active product grants for this workspace membership.
     */
    public function forWorkspace(
        PlatformUser $user,
        Workspace $workspace,
        Collection $projects,
        array $products,
        int $limit,
    ): WorkspaceNativeNotificationSnapshot;

    /** Re-resolve identity, membership, source recipient and resource ACL before changing source read state. */
    public function setRead(
        PlatformUser $user,
        Workspace $workspace,
        Collection $projects,
        array $products,
        string $sourceReference,
        bool $read,
    ): bool;
}
