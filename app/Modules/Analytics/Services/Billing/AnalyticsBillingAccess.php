<?php

namespace App\Modules\Analytics\Services\Billing;

use App\Core\Enums\ProductKey;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Core\Models\WorkspaceMembership;
use App\Core\Services\Auth\ProductAuthentication;
use App\Core\Services\LegacyIdentityResolver;
use App\Core\Services\WorkspaceProjectAccess;
use App\Modules\Analytics\Models\User as AnalyticsUser;
use App\Modules\Analytics\Models\Workspace as AnalyticsWorkspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;

/** Authorize Analytics billing through a single current Core/native identity mapping. */
final class AnalyticsBillingAccess
{
    public function __construct(
        private readonly LegacyIdentityResolver $identities,
        private readonly WorkspaceProjectAccess $workspaceAccess,
        private readonly ProductAuthentication $authentication,
    ) {}

    public function authorize(AnalyticsWorkspace $analyticsWorkspace, PlatformUser $actor): AnalyticsBillingContext
    {
        $authenticatedActor = Auth::guard('platform')->user();
        $actorId = (string) $actor->getAuthIdentifier();

        if (! $this->authentication->usesCoreAuthority(ProductKey::Analytics->value)
            || ! $authenticatedActor instanceof PlatformUser
            || (string) $authenticatedActor->getAuthIdentifier() !== $actorId
            || $actor->status !== 'active'
            || ! $actor->hasVerifiedEmail()
            || ! PlatformUser::query()->whereKey($actorId)->where('status', 'active')->exists()) {
            throw $this->denied();
        }

        $analyticsWorkspaceId = (string) $analyticsWorkspace->getKey();
        if ($analyticsWorkspaceId === '' || ! AnalyticsWorkspace::query()->whereKey($analyticsWorkspaceId)->exists()) {
            throw $this->denied();
        }

        $coreWorkspaceId = $this->identities->canonicalIdForSource(
            product: ProductKey::Analytics->value,
            sourceEntity: 'workspace',
            sourceId: $analyticsWorkspaceId,
            canonicalEntity: 'workspace',
        );
        if (! is_string($coreWorkspaceId) || $coreWorkspaceId === '') {
            throw $this->denied();
        }

        $nativeWorkspaceIds = $this->identities->sourceIdsForCanonical(
            product: ProductKey::Analytics->value,
            sourceEntity: 'workspace',
            canonicalId: $coreWorkspaceId,
            canonicalEntity: 'workspace',
        );
        if (count($nativeWorkspaceIds) !== 1 || $nativeWorkspaceIds[0] !== $analyticsWorkspaceId) {
            throw $this->denied();
        }

        $coreWorkspace = CoreWorkspace::query()
            ->whereKey($coreWorkspaceId)
            ->where('status', 'active')
            ->whereNull('archived_at')
            ->first();
        if (! $coreWorkspace instanceof CoreWorkspace) {
            throw $this->denied();
        }

        $nativeUserIds = $this->identities->sourceIdsForCanonical(
            product: ProductKey::Analytics->value,
            sourceEntity: 'user',
            canonicalId: $actorId,
            canonicalEntity: 'user',
        );
        if (count($nativeUserIds) !== 1) {
            throw $this->denied();
        }

        $nativeUserId = $nativeUserIds[0];
        $mappedActorId = $this->identities->canonicalIdForSource(
            product: ProductKey::Analytics->value,
            sourceEntity: 'user',
            sourceId: $nativeUserId,
            canonicalEntity: 'user',
        );
        $analyticsUser = AnalyticsUser::query()->whereKey($nativeUserId)->first();

        if ($mappedActorId !== $actorId
            || ! $analyticsUser instanceof AnalyticsUser
            || ($analyticsUser->platform_user_id !== null && (string) $analyticsUser->platform_user_id !== $actorId)
            || ! $analyticsWorkspace->users()->where('users.id', $nativeUserId)->exists()) {
            throw $this->denied();
        }

        $membership = WorkspaceMembership::query()
            ->where('workspace_id', $coreWorkspace->getKey())
            ->where('user_id', $actorId)
            ->currentlyActive()
            ->first();
        if (! $membership instanceof WorkspaceMembership
            || ! in_array($membership->role, ['owner', 'billing'], true)
            || ! $this->workspaceAccess->canManageBilling($actor, $coreWorkspace)
            || ! $this->workspaceAccess->hasProductAccess($membership, ProductKey::Analytics)) {
            throw $this->denied();
        }

        $accountKey = config('analytics.billing.stripe.account_id');

        return new AnalyticsBillingContext(
            analyticsWorkspace: $analyticsWorkspace,
            coreWorkspace: $coreWorkspace,
            actor: $actor,
            membership: $membership,
            nativeUserId: $nativeUserId,
            providerAccountKey: is_string($accountKey) && preg_match('/^acct_[A-Za-z0-9]+$/', $accountKey) ? $accountKey : '',
        );
    }

    private function denied(): AuthorizationException
    {
        return new AuthorizationException('You cannot manage billing for this Analytics workspace.');
    }
}
