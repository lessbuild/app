<?php

namespace App\Core\Contracts;

use App\Core\Data\Monitor\MonitorMaintenanceWindowSnapshot;
use App\Core\Data\Monitor\MonitorMutationResult;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;

interface WorkspaceMonitorMaintenanceWindowAdministrationProvider
{
    /** @param array<string, int|string> $filters */
    public function snapshot(PlatformUser $user, Workspace $workspace, array $filters = []): ?MonitorMaintenanceWindowSnapshot;

    /** @param array<string, mixed> $data */
    public function saveWindow(PlatformUser $user, Workspace $workspace, ?string $windowReference, array $data): MonitorMutationResult;

    public function deleteWindow(PlatformUser $user, Workspace $workspace, string $windowReference, string $version, bool $confirmRemove): MonitorMutationResult;
}
