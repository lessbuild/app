<?php

namespace App\Core\Contracts;

use App\Core\Data\Monitor\MonitorMutationResult;
use App\Core\Data\Monitor\MonitorServiceObjectiveSnapshot;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;

interface WorkspaceMonitorServiceObjectiveAdministrationProvider
{
    /** @param array<string, int|string> $filters */
    public function snapshot(PlatformUser $user, Workspace $workspace, array $filters = []): ?MonitorServiceObjectiveSnapshot;

    /** @param array<string, mixed> $data */
    public function saveObjective(PlatformUser $user, Workspace $workspace, ?string $objectiveReference, array $data): MonitorMutationResult;

    public function archiveObjective(PlatformUser $user, Workspace $workspace, string $objectiveReference, string $version, bool $confirmArchive): MonitorMutationResult;
}
