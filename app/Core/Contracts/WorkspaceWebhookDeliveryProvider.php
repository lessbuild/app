<?php

namespace App\Core\Contracts;

use App\Core\Data\Projects\WorkspaceWebhookDeliverySnapshot;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\Workspace;
use Illuminate\Support\Collection;

interface WorkspaceWebhookDeliveryProvider
{
    /**
     * @param  Collection<int, Project>  $projects  Active, membership-visible projects with an active grant for this product.
     */
    public function recentWebhookDeliveriesForWorkspace(
        PlatformUser $user,
        Workspace $workspace,
        Collection $projects,
        int $limit,
    ): WorkspaceWebhookDeliverySnapshot;
}
