<?php

namespace App\Core\Contracts;

use App\Core\Data\Status\WorkspaceCustomerStatusManagement;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;

/** Keeps customer-status records and mutations inside the owning product module. */
interface WorkspaceCustomerStatusManagementProvider
{
    public function forWorkspace(PlatformUser $user, Workspace $workspace): ?WorkspaceCustomerStatusManagement;

    /** @param array<string, mixed> $attributes */
    public function createPage(PlatformUser $user, Workspace $workspace, array $attributes): bool;

    /** @param array<string, mixed> $attributes */
    public function updatePage(PlatformUser $user, Workspace $workspace, string $pageId, array $attributes): bool;

    public function deletePage(PlatformUser $user, Workspace $workspace, string $pageId): bool;

    /** @param array<string, mixed> $attributes */
    public function createIncident(PlatformUser $user, Workspace $workspace, array $attributes): bool;

    /** @param array<string, mixed> $attributes */
    public function updateIncident(PlatformUser $user, Workspace $workspace, string $incidentId, array $attributes): bool;
}
