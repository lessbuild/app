<?php

namespace App\Core\Contracts;

use App\Core\Data\Monitor\MonitorConfigurationSnapshot;
use App\Core\Data\Monitor\MonitorMutationResult;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;

interface WorkspaceMonitorConfigurationAdministrationProvider
{
    /** @param array<string, int|string> $filters */
    public function snapshot(PlatformUser $user, Workspace $workspace, array $filters = []): ?MonitorConfigurationSnapshot;

    /** @param array<string, mixed> $data */
    public function updateApplication(PlatformUser $user, Workspace $workspace, string $applicationReference, array $data): MonitorMutationResult;

    /** @param array<string, mixed> $data */
    public function updateEnvironment(PlatformUser $user, Workspace $workspace, string $environmentReference, array $data): MonitorMutationResult;

    /** @param array<string, mixed> $data */
    public function updateMonitor(PlatformUser $user, Workspace $workspace, string $monitorReference, array $data): MonitorMutationResult;

    /** @param array<string, mixed> $data */
    public function createHttpCheck(PlatformUser $user, Workspace $workspace, string $environmentReference, array $data): MonitorMutationResult;
}
