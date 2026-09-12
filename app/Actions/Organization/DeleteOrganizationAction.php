<?php

namespace App\Actions\Organization;

use App\Exceptions\OrganizationDeletionOperationException;
use App\Models\Build;
use App\Models\Organization;
use App\Models\ServerCommandExecution;
use App\Models\User;
use App\Services\PersonalOrganization;
use App\Services\TwoFactorAuthentication;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteOrganizationAction
{
    public function __construct(
        private readonly TwoFactorAuthentication $twoFactor,
        private readonly PersonalOrganization $personal,
    ) {}

    /**
     * Verify the destructive-workflow challenges, enforce workspace safety guards, and delete the workspace.
     *
     * The checks intentionally remain outside the transaction, matching the existing workflow. The personal
     * workspace recovery runs after deletion so its own user-row lock and creation transaction remain intact.
     *
     * @param  array<string, mixed>  $attributes  Validated confirmation and authentication challenge values.
     */
    public function handle(User $actor, Organization $organization, array $attributes): void
    {
        if ($actor->twoFactorEnabled() && ! $this->twoFactor->verifyUser($actor, (string) $attributes['code'])) {
            throw ValidationException::withMessages(['code' => __('The authentication or recovery code is invalid.')])->errorBag('deleteWorkspace');
        }

        if ($organization->members()->where('users.id', '!=', $actor->id)->exists()) {
            throw new OrganizationDeletionOperationException('Remove every teammate before deleting this workspace.', 422);
        }
        if ($this->hasActiveOperations($organization->id)) {
            throw new OrganizationDeletionOperationException('Wait for active deployments and commands to finish before deleting this workspace.', 409);
        }

        DB::transaction(function () use ($organization, $actor): void {
            $organization->delete();
            $actor->forceFill(['current_organization_id' => null])->save();
        });
        $this->personal->ensure($actor->refresh());
    }

    private function hasActiveOperations(int $organizationId): bool
    {
        return Build::query()->whereIn('status', Build::ACTIVE_STATUSES)->whereHas('repository', fn ($query) => $query->where('organization_id', $organizationId))->exists()
            || ServerCommandExecution::query()->active()->whereHas('server', fn ($query) => $query->where('organization_id', $organizationId))->exists();
    }
}
