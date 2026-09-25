<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Contracts\WorkspaceCredentialProvider;
use App\Core\Data\Credentials\WorkspaceCredential;
use App\Core\Data\Credentials\WorkspaceCredentialSnapshot;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\Workspace;
use App\Core\Services\Identity\ProductWorkspaceAccess;
use App\Core\Services\LegacyIdentityResolver;
use App\Core\Services\PlatformProductRouteLinks;
use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\User;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Laravel\Sanctum\PersonalAccessToken;

/** Project API tokens remain Deployer-owned; Core receives only the actor's redacted inventory. */
final class DeployerWorkspaceCredentialProvider implements WorkspaceCredentialProvider
{
    public function __construct(
        private readonly LegacyIdentityResolver $identities,
        private readonly ProductWorkspaceAccess $workspaceAccess,
        private readonly PlatformProductRouteLinks $productLinks,
    ) {}

    /**
     * @param  Collection<int, Project>  $projects
     */
    public function credentialsForWorkspace(
        PlatformUser $user,
        Workspace $workspace,
        Collection $projects,
        int $limit,
    ): WorkspaceCredentialSnapshot {
        $userIds = $this->identities->sourceIdsFor($user, 'deployer');
        $organizationIds = $this->identities->sourceIdsForCanonical(
            'deployer',
            'organization',
            (string) $workspace->getKey(),
            'workspace',
        );

        if (count($userIds) !== 1 || count($organizationIds) !== 1) {
            return new WorkspaceCredentialSnapshot(collect());
        }

        try {
            $localUser = User::query()->find($userIds[0]);
            $organization = Organization::query()->find($organizationIds[0]);

            if ($localUser === null
                || $organization === null
                || $organization->roleFor($localUser) === null
                || ! $this->workspaceAccess->allows($user, 'deployer', 'organization', (string) $organization->getKey())) {
                return new WorkspaceCredentialSnapshot(collect());
            }

            $workspaceClaims = ['workspace:'.$organization->getKey()];
            $manageUrl = $this->productLinks->to('deployer', 'automation.index', [
                'organization_id' => (string) $organization->getKey(),
            ]);
            $limit = max(1, min(100, $limit));

            $credentials = $localUser->tokens()
                ->latest('created_at')
                ->orderByDesc('id')
                ->select(['id', 'name', 'abilities', 'last_used_at', 'expires_at', 'created_at'])
                ->cursor()
                ->filter(function (PersonalAccessToken $token) use ($workspaceClaims): bool {
                    $claims = collect($token->abilities)
                        ->filter(fn (mixed $ability): bool => is_string($ability)
                            && str_starts_with($ability, 'workspace:'));

                    // Match Deployer's existing Automation view: legacy unscoped tokens remain visible for cleanup.
                    return $claims->isEmpty() || $claims->contains($workspaceClaims[0]);
                })
                ->take($limit)
                ->map(fn (PersonalAccessToken $token): WorkspaceCredential => $this->credential($token, $workspace, $manageUrl))
                ->collect()
                ->values();

            return new WorkspaceCredentialSnapshot($credentials);
        } catch (LostConnectionException|QueryException) {
            return new WorkspaceCredentialSnapshot(collect(), available: false);
        }
    }

    private function credential(PersonalAccessToken $token, Workspace $workspace, ?string $manageUrl): WorkspaceCredential
    {
        $abilities = collect($token->abilities)->filter(fn (mixed $ability): bool => is_string($ability));
        $workspaceClaims = $abilities->filter(fn (string $ability): bool => str_starts_with($ability, 'workspace:'));
        $projectClaims = $abilities->filter(fn (string $ability): bool => str_starts_with($ability, 'project:'));
        $permissions = $abilities
            ->reject(fn (string $ability): bool => str_starts_with($ability, 'workspace:') || str_starts_with($ability, 'project:'))
            ->values()
            ->all();
        $isLegacy = $workspaceClaims->isEmpty();
        $scope = $isLegacy
            ? __('Legacy token without a workspace restriction')
            : ($workspaceClaims->count() === 1
                ? __('Workspace: :workspace', ['workspace' => $workspace->name])
                : __(':count workspace scopes; review in Deployer', ['count' => $workspaceClaims->count()]));

        if ($projectClaims->isNotEmpty()) {
            $scope .= ' · '.__(':count project restrictions', ['count' => $projectClaims->count()]);
        }
        if ($permissions !== []) {
            $scope .= ' · '.__('Permissions: :permissions', ['permissions' => implode(', ', $permissions)]);
        }

        $expiresAt = $token->expires_at?->toImmutable()->utc();
        $status = $expiresAt !== null && $expiresAt->isPast() ? 'expired' : 'active';

        return new WorkspaceCredential(
            key: 'deployer:personal-access-token:'.$token->getKey(),
            product: 'deployer',
            productLabel: (string) config('platform.products.deployer.label', __('Deployer')),
            type: __('Deployer API token'),
            name: (string) $token->name,
            scope: $scope,
            status: $status,
            statusLabel: $status === 'expired' ? __('Expired') : __('Active'),
            prefix: null,
            createdAt: $token->created_at?->toImmutable()->utc(),
            lastUsedAt: $token->last_used_at?->toImmutable()->utc(),
            expiresAt: $expiresAt,
            manageUrl: $manageUrl,
        );
    }
}
