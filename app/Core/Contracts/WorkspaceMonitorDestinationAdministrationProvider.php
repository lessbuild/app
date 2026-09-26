<?php

namespace App\Core\Contracts;

use App\Core\Data\Monitor\MonitorAdministrationSnapshot;
use App\Core\Data\Monitor\MonitorMutationResult;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;

interface WorkspaceMonitorDestinationAdministrationProvider
{
    /** @param array<string, int|string> $filters */
    public function snapshot(PlatformUser $user, Workspace $workspace, array $filters = []): MonitorAdministrationSnapshot;

    /** @param array<string, mixed> $data */
    public function saveDestination(PlatformUser $user, Workspace $workspace, ?string $destinationReference, array $data): MonitorMutationResult;

    public function archiveDestination(PlatformUser $user, Workspace $workspace, string $destinationReference, int $version): MonitorMutationResult;

    public function rotateDestination(PlatformUser $user, Workspace $workspace, string $destinationReference, int $version): MonitorMutationResult;

    public function testDestination(PlatformUser $user, Workspace $workspace, string $destinationReference, int $version): MonitorMutationResult;

    public function retryDelivery(PlatformUser $user, Workspace $workspace, string $deliveryReference, int $generation): MonitorMutationResult;
}
