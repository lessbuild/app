<?php

namespace App\Core\Contracts;

use App\Core\Data\Status\WorkspaceMonitorStatusManagement;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;

/** Manages Monitor-owned public status pages through the Monitor module boundary. */
interface WorkspaceMonitorStatusManagementProvider
{
    public function forWorkspace(PlatformUser $user, Workspace $workspace): ?WorkspaceMonitorStatusManagement;

    /** @param array<string, mixed> $attributes */
    public function create(PlatformUser $user, Workspace $workspace, array $attributes): bool;

    /** @param array<string, mixed> $attributes */
    public function update(PlatformUser $user, Workspace $workspace, string $pageId, array $attributes): bool;

    public function delete(PlatformUser $user, Workspace $workspace, string $pageId): bool;
}
