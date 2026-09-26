<?php

namespace App\Core\Contracts;

use App\Core\Data\Monitor\MonitorAdministrationSnapshot;
use App\Core\Data\Monitor\MonitorMutationResult;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;

interface WorkspaceMonitorAlertAdministrationProvider
{
    /** @param array<string, int|string> $filters */
    public function snapshot(PlatformUser $user, Workspace $workspace, array $filters = []): MonitorAdministrationSnapshot;

    /** @param array<string, mixed> $data */
    public function saveRule(PlatformUser $user, Workspace $workspace, ?string $ruleReference, array $data): MonitorMutationResult;

    public function archiveRule(PlatformUser $user, Workspace $workspace, string $ruleReference, int $version): MonitorMutationResult;

    /** @param array<string, mixed> $data */
    public function saveRouting(PlatformUser $user, Workspace $workspace, string $ruleReference, array $data): MonitorMutationResult;

    /** @param array<string, mixed> $data */
    public function saveEscalations(PlatformUser $user, Workspace $workspace, string $ruleReference, array $data): MonitorMutationResult;
}
