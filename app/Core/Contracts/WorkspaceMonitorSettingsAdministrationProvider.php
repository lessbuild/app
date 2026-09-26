<?php

namespace App\Core\Contracts;

use App\Core\Data\Monitor\MonitorAdministrationSnapshot;
use App\Core\Data\Monitor\MonitorMutationResult;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;

interface WorkspaceMonitorSettingsAdministrationProvider
{
    public function snapshot(PlatformUser $user, Workspace $workspace): MonitorAdministrationSnapshot;

    public function integrationGuide(PlatformUser $user, Workspace $workspace): MonitorAdministrationSnapshot;

    /** @param array<string, int|string> $filters */
    public function auditSnapshot(PlatformUser $user, Workspace $workspace, array $filters = []): MonitorAdministrationSnapshot;

    /** @param array<string, mixed> $data */
    public function saveNotificationPreferences(PlatformUser $user, Workspace $workspace, array $data): MonitorMutationResult;

    /** @param resource $output */
    public function writeExport(PlatformUser $user, Workspace $workspace, mixed $output): void;
}
