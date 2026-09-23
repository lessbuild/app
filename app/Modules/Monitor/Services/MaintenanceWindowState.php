<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\MaintenanceWindow;
use Carbon\CarbonImmutable;

final class MaintenanceWindowState
{
    public function activeForWorkspaceId(int $workspaceId, CarbonImmutable $at): ?MaintenanceWindow
    {
        return MaintenanceWindow::query()->where('workspace_id', $workspaceId)
            ->where('starts_at', '<=', $at)->where('ends_at', '>', $at)->oldest('starts_at')->first();
    }

    public function isActiveForWorkspaceId(int $workspaceId, CarbonImmutable $at): bool
    {
        return $this->activeForWorkspaceId($workspaceId, $at) !== null;
    }
}
