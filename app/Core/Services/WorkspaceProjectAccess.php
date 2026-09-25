<?php

namespace App\Core\Services;

use App\Core\Enums\ProductKey;
use App\Core\Enums\ProjectResourceAccessPurpose;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectMembership;
use App\Core\Models\ProjectProduct;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceMembership;
use App\Core\Models\WorkspaceProductAccess;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

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
            ->currentlyActive()
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
        if (! $membership->currentlyActive()) {
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
    ): bool {
        $product = $this->productKey($product);

        if ($product === null || ! $this->canLinkProductResource($user, $project, $product)) {
            return false;
        }

        return ProjectProduct::query()
            ->where('project_id', $project->getKey())
            ->where('product', $product)
            ->where('status', 'active')
            ->exists();
    }

    /** @return Builder<Project> */
    public function accessibleProductProjects(
        PlatformUser $user,
        Workspace $workspace,
        ProductKey|string $product,
        ProjectResourceAccessPurpose $purpose = ProjectResourceAccessPurpose::Interactive,
    ): Builder {
        $query = Project::query()->where('workspace_id', $workspace->getKey());
        $product = $this->productKey($product);
        $membership = $this->activeMembership($user, $workspace);

        if ($product === null || $membership === null || ! $this->hasProductAccess($membership, $product)) {
            return $query->whereRaw('1 = 0');
        }

        $retainedRead = in_array($purpose, [
            ProjectResourceAccessPurpose::HistoricalExport,
            ProjectResourceAccessPurpose::RetainedRead,
        ], true);
        $retainedProduct = $retainedRead || $purpose === ProjectResourceAccessPurpose::Restoration;

        // Archiving retained data is distinct from revoking a member's access.
        // Reads never reactivate anything. A restore operation additionally requires
        // the user to have explicitly restored the shared project beforehand.
        return $query->where(fn (Builder $projects) => $projects
            ->where(fn (Builder $active) => $active->where('status', 'active')->whereNull('archived_at'))
            ->when($retainedRead, fn (Builder $archives) => $archives->orWhere('status', 'archived')))
            ->whereHas('memberships', fn (Builder $members) => $members
                ->where('user_id', $user->getKey())->where('status', 'active')->whereNull('revoked_at'))
            ->whereHas('products', fn (Builder $products) => $products
                ->where('product', $product)->whereIn('status', $retainedProduct ? ['active', 'inactive'] : ['active']));
    }

    /**
     * Linking an existing product resource may activate its project module, so
     * this checks project membership and the workspace grant without requiring
     * a ProjectProduct row to exist yet.
     */
    public function canLinkProductResource(
        PlatformUser $user,
        Project $project,
        ProductKey|string $product,
    ): bool {
        $product = $this->productKey($product);

        if ($product === null || ! $this->canViewProject($user, $project)) {
            return false;
        }

        $membership = $this->activeMembership($user, $project->workspace);

        return $membership !== null && $this->hasProductAccess($membership, $product);
    }

    private function productKey(ProductKey|string $product): ?string
    {
        return $product instanceof ProductKey
            ? $product->value
            : ProductKey::tryFrom($product)?->value;
    }
}
