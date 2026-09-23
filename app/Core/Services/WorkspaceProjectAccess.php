<?php

namespace App\Core\Services;

use App\Core\Enums\ProductKey;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectMembership;
use App\Core\Models\ProjectProduct;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceMembership;
use App\Core\Models\WorkspaceProductAccess;
use DateTimeInterface;

/**
 * Central, fail-closed checks for canonical workspace, project, and product access.
 * Product modules should call this service instead of inferring access from navigation.
 */
final class WorkspaceProjectAccess
{
    public function activeMembership(PlatformUser $user, Workspace $workspace): ?WorkspaceMembership
    {
        if ($workspace->status !== 'active' || $workspace->archived_at !== null) {
            return null;
        }

        return WorkspaceMembership::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('user_id', $user->getKey())
            ->where('status', 'active')
            ->first();
    }

    public function canManageWorkspace(PlatformUser $user, Workspace $workspace): bool
    {
        $membership = $this->activeMembership($user, $workspace);

        return $membership !== null && in_array($membership->role, ['owner', 'admin'], true);
    }

    public function canManageBilling(PlatformUser $user, Workspace $workspace): bool
    {
        $membership = $this->activeMembership($user, $workspace);

        return $membership !== null && in_array($membership->role, ['owner', 'billing'], true);
    }

    public function hasProductAccess(
        WorkspaceMembership $membership,
        ProductKey|string $product,
        ?DateTimeInterface $at = null,
    ): bool {
        if ($membership->status !== 'active') {
            return false;
        }

        $product = $this->productKey($product);

        if ($product === null) {
            return false;
        }

        $now = $at ?? now();

        return WorkspaceProductAccess::query()
            ->where('membership_id', $membership->getKey())
            ->where('product', $product)
            ->where('status', 'active')
            ->where(function ($query) use ($now): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            })
            ->whereNull('revoked_at')
            ->exists();
    }

    public function canViewProject(PlatformUser $user, Project $project): bool
    {
        if ($project->status !== 'active' || $project->archived_at !== null) {
            return false;
        }

        $workspace = $project->workspace;

        if ($workspace === null) {
            return false;
        }

        $membership = $this->activeMembership($user, $workspace);

        return $membership !== null
            && ProjectMembership::query()
                ->where('project_id', $project->getKey())
                ->where('user_id', $user->getKey())
                ->where('status', 'active')
                ->whereNull('revoked_at')
                ->exists();
    }

    public function canAccessProductResource(
        PlatformUser $user,
        Project $project,
        ProductKey|string $product,
    ): bool
    {
        $product = $this->productKey($product);

        if ($product === null) {
            return false;
        }

        if (! $this->canViewProject($user, $project)) {
            return false;
        }

        $membership = $this->activeMembership($user, $project->workspace);

        if ($membership === null || ! $this->hasProductAccess($membership, $product)) {
            return false;
        }

        return ProjectProduct::query()
            ->where('project_id', $project->getKey())
            ->where('product', $product)
            ->where('status', 'active')
            ->exists();
    }

    private function productKey(ProductKey|string $product): ?string
    {
        return $product instanceof ProductKey
            ? $product->value
            : ProductKey::tryFrom($product)?->value;
    }
}
