<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Contracts\WorkspaceCredentialMutationProvider;
use App\Core\Data\Credentials\CredentialCreateOption;
use App\Core\Data\Credentials\CredentialCreateOptions;
use App\Core\Data\Credentials\CredentialMutationCommand;
use App\Core\Data\Credentials\CredentialMutationOutcome;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project as CoreProject;
use App\Core\Models\ProjectResource;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Core\Services\Identity\ProductWorkspaceAccess;
use App\Core\Services\LegacyIdentityResolver;
use App\Core\Services\WorkspaceProjectAccess;
use App\Modules\Deployer\Actions\Automation\CreatePersonalAccessTokenAction;
use App\Modules\Deployer\Actions\Automation\RevokePersonalAccessTokenAction;
use App\Modules\Deployer\Actions\Automation\RotatePersonalAccessTokenAction;
use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\ProductDeletionFence;
use App\Modules\Deployer\Models\Project as DeployerProject;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\Entitlements;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/** Deployer-issued Sanctum tokens remain owned and hashed by Deployer. */
final class DeployerWorkspaceCredentialMutationProvider implements WorkspaceCredentialMutationProvider
{
    public function __construct(
        private readonly LegacyIdentityResolver $identities,
        private readonly ProductWorkspaceAccess $workspaceAccess,
        private readonly WorkspaceProjectAccess $projectAccess,
    ) {}

    public function createOptions(PlatformUser $actor, CoreWorkspace $workspace, Collection $projects): CredentialCreateOptions
    {
        [$user, $organization] = $this->resolve($actor, $workspace);
        if (! $user || ! $organization || (string) $organization->owner_id !== (string) $user->getKey()
            || ! $user->hasVerifiedEmail()
            || ! $this->workspaceAccess->allows($actor, 'deployer', 'organization', (string) $organization->getKey())) {
            return new CredentialCreateOptions(collect());
        }

        $scopeOptions = $projects->filter(fn (CoreProject $project): bool => (string) $project->workspace_id === (string) $workspace->getKey()
                && $this->projectAccess->canAccessProductResource($actor, $project, 'deployer')
                && ProjectResource::query()->where('project_id', $project->getKey())->where('product', 'deployer')
                    ->where('resource_type', 'project')->where('status', 'active')->exists())
            ->map(fn (CoreProject $project): array => ['key' => (string) $project->getKey(), 'label' => (string) $project->name])->values()->all();

        return new CredentialCreateOptions(collect([new CredentialCreateOption(
            'deployer', 'personal-access-token', __('Deployer API token'), 'deployer:workspace:'.$organization->getKey(),
            __('Workspace: :name', ['name' => $workspace->name]), $scopeOptions, true, true, ['read', 'deploy', 'manage'], 365,
        )]));
    }

    public function mutate(PlatformUser $actor, CoreWorkspace $workspace, CredentialMutationCommand $command): CredentialMutationOutcome
    {
        abort_unless($command->product === 'deployer', 404);
        [$userId, $organizationId] = $this->sourceIds($actor, $workspace);

        return DB::connection('deployer')->transaction(function () use ($actor, $workspace, $command, $userId, $organizationId): CredentialMutationOutcome {
            $user = User::query()->lockForUpdate()->findOrFail($userId);
            $organization = Organization::query()->lockForUpdate()->findOrFail($organizationId);
            $this->assertActive($actor, $workspace, $user, $organization, in_array($command->action, ['create', 'rotate'], true));
            $previous = DB::connection('deployer')->table('credential_mutation_receipts')->where('operation_id', $command->operationId)->lockForUpdate()->first();
            if ($previous !== null) {
                abort_unless((string) $previous->actor_source_id === (string) $user->getKey()
                    && (string) $previous->workspace_source_id === (string) $organization->getKey()
                    && $previous->action === $command->action
                    && hash_equals((string) $previous->input_hash, $command->inputHash), 409, 'Credential operation receipt conflicts with this request.');
                if (in_array($previous->action, ['create', 'rotate'], true)) {
                    $issuedTokenId = $this->tokenId((string) $previous->credential_key);
                    $issuedToken = $user->tokens()->whereKey($issuedTokenId)->first();
                    abort_unless($issuedToken !== null && (string) $issuedToken->tokenable_id === (string) $user->getKey()
                        && $issuedToken->tokenable_type === $user->getMorphClass(), 404, 'The issued credential is no longer owned by this account.');
                    $this->assertTokenScope($issuedToken, $organization, $workspace, $actor);
                    app(Entitlements::class)->enforce($organization, 'api');
                }
                $this->assertActive($actor, $workspace, $user, $organization, in_array($command->action, ['create', 'rotate'], true));

                return new CredentialMutationOutcome($previous->action === 'revoke' ? 'revoked' : 'secret_unavailable', (string) $previous->credential_key, 'personal-access-token', (string) $previous->credential_name);
            }
            abort_unless(! $command->replayOnly, 409, 'The native credential receipt is unavailable; the operation was not repeated.');

            $secret = null;
            $credentialId = null;
            $name = $command->name ?? '';
            if ($command->action === 'create') {
                abort_unless($command->credentialType === 'personal-access-token'
                    && hash_equals('deployer:workspace:'.$organization->getKey(), (string) $command->targetKey), 404);
                abort_unless((string) $organization->owner_id === (string) $user->getKey(), 403);
                abort_unless(filled($command->name) && mb_strlen((string) $command->name) <= 100, 422, 'A token name of at most 100 characters is required.');
                $projectIds = $this->nativeProjectIds($actor, $workspace, $organization, $command->canonicalProjectIds);
                $expires = $command->expiresInDays ?? 365;
                abort_unless(in_array($expires, [30, 90, 180, 365], true), 422, 'Choose a supported token expiration period.');
                $issued = app(CreatePersonalAccessTokenAction::class)->handle($user, [
                    'name' => $name,
                    'abilities' => $this->permissions($command->permissions),
                    'expires_in_days' => $expires,
                    'project_ids' => $projectIds,
                ], $organization);
                $credentialId = (string) $issued->accessToken->getKey();
                $secret = $issued->plainTextToken;
            } elseif (in_array($command->action, ['rotate', 'revoke'], true)) {
                $tokenId = $this->tokenId((string) $command->credentialKey);
                $token = $user->tokens()->whereKey($tokenId)->lockForUpdate()->firstOrFail();
                abort_unless((string) $token->tokenable_id === (string) $user->getKey() && $token->tokenable_type === $user->getMorphClass(), 404);
                $name = (string) $token->name;
                if ($command->action === 'rotate') {
                    $this->assertTokenScope($token, $organization, $workspace, $actor);
                    abort_if($token->expires_at !== null && $token->expires_at->isPast(), 409, 'Expired credentials cannot be rotated from Core; revoke this key and create a replacement.');
                    $issued = app(RotatePersonalAccessTokenAction::class)->handle($user, $token, $organization);
                    $credentialId = (string) $issued->accessToken->getKey();
                    $secret = $issued->plainTextToken;
                } else {
                    app(RevokePersonalAccessTokenAction::class)->handle($token);
                    $credentialId = $tokenId;
                }
            } else {
                abort(422, 'Unsupported credential operation.');
            }

            $credentialKey = 'deployer:personal-access-token:'.$credentialId;
            DB::connection('deployer')->table('credential_mutation_receipts')->insert([
                'operation_id' => $command->operationId, 'actor_source_id' => $user->getKey(), 'workspace_source_id' => $organization->getKey(),
                'action' => $command->action, 'input_hash' => $command->inputHash, 'credential_key' => $credentialKey,
                'credential_type' => 'personal-access-token', 'credential_name' => $name, 'created_at' => now(),
            ]);
            $this->assertActive($actor, $workspace, $user, $organization, in_array($command->action, ['create', 'rotate'], true));

            return new CredentialMutationOutcome($command->action === 'revoke' ? 'revoked' : 'issued', $credentialKey, 'personal-access-token', $name, $secret);
        }, attempts: 3);
    }

    private function resolve(PlatformUser $actor, CoreWorkspace $workspace): array
    {
        $users = $this->identities->sourceIdsFor($actor, 'deployer');
        $organizations = $this->identities->sourceIdsForCanonical('deployer', 'organization', (string) $workspace->getKey(), 'workspace');
        if (count($users) !== 1 || count($organizations) !== 1) {
            return [null, null];
        }

        return [User::query()->find($users[0]), Organization::query()->find($organizations[0])];
    }

    private function sourceIds(PlatformUser $actor, CoreWorkspace $workspace): array
    {
        $users = $this->identities->sourceIdsFor($actor, 'deployer');
        $organizations = $this->identities->sourceIdsForCanonical('deployer', 'organization', (string) $workspace->getKey(), 'workspace');
        abort_unless(count($users) === 1 && count($organizations) === 1, 404, 'Deployer identity mapping is unavailable.');

        return [$users[0], $organizations[0]];
    }

    private function assertActive(PlatformUser $actor, CoreWorkspace $workspace, User $user, Organization $organization, bool $requireOwner): void
    {
        DB::connection('deployer')->table('users')->where('id', $user->getKey())->update(['id' => DB::raw('id')]);
        DB::connection('deployer')->table('organizations')->where('id', $organization->getKey())->update(['id' => DB::raw('id')]);
        [$userId, $organizationId] = $this->sourceIds($actor, $workspace);
        abort_unless((string) $userId === (string) $user->getKey()
            && (string) $organizationId === (string) $organization->getKey()
            && User::query()->whereKey($userId)->whereNotNull('email_verified_at')->exists(), 403);
        abort_if(ProductDeletionFence::query()->where('kind', 'account')->where('source_id', (string) $user->getKey())->exists()
            || ProductDeletionFence::query()->where('kind', 'workspace')->where('source_id', (string) $organization->getKey())->exists(), 410, 'Credential changes are unavailable during deletion.');
        abort_unless(DB::connection('deployer')->table('organization_user')->where('organization_id', $organization->getKey())->where('user_id', $user->getKey())->exists()
            && $this->workspaceAccess->allows($actor, 'deployer', 'organization', (string) $organization->getKey()), 403);
        abort_unless(! $requireOwner || (string) $organization->owner_id === (string) $user->getKey(), 403);
    }

    private function nativeProjectIds(PlatformUser $actor, CoreWorkspace $workspace, Organization $organization, array $canonicalIds): array
    {
        if ($canonicalIds === []) {
            return [];
        }
        $ids = [];
        foreach (array_unique(array_map('strval', $canonicalIds)) as $canonicalId) {
            abort_unless(Str::isUlid($canonicalId), 422, 'Invalid project scope.');
            $project = CoreProject::query()->where('workspace_id', $workspace->getKey())->where('status', 'active')->findOrFail($canonicalId);
            abort_unless($this->projectAccess->canAccessProductResource($actor, $project, 'deployer'), 403);
            $resources = ProjectResource::query()->where('project_id', $project->getKey())->where('product', 'deployer')->where('resource_type', 'project')->where('status', 'active')->pluck('resource_id')->unique();
            abort_unless($resources->count() === 1, 409, 'Project mapping is unavailable or ambiguous.');
            abort_unless(DeployerProject::query()->whereKey($resources->first())->where('organization_id', $organization->getKey())->lockForUpdate()->exists(), 404);
            abort_unless($project->memberships()->where('user_id', $actor->getKey())->where('status', 'active')->whereNull('revoked_at')->exists(), 403);
            $ids[] = (int) $resources->first();
        }

        return $ids;
    }

    private function tokenId(string $key): string
    {
        abort_unless(preg_match('/^deployer:personal-access-token:(\d+)$/', $key, $matches) === 1, 404);

        return $matches[1];
    }

    private function assertTokenScope(PersonalAccessToken $token, Organization $organization, CoreWorkspace $workspace, ?PlatformUser $actor = null): void
    {
        $abilities = collect($token->abilities)->filter(fn ($ability): bool => is_string($ability));
        $workspaceClaims = $abilities->filter(fn (string $ability): bool => str_starts_with($ability, 'workspace:'))->values();
        abort_unless($workspaceClaims->isEmpty() || ($workspaceClaims->count() === 1 && $workspaceClaims->first() === 'workspace:'.$organization->getKey()), 404);

        $projectClaims = $abilities->filter(fn (string $ability): bool => str_starts_with($ability, 'project:'));
        foreach ($projectClaims as $claim) {
            $id = substr($claim, strlen('project:'));
            abort_unless(ctype_digit($id), 404);
            $mapped = ProjectResource::query()->where('product', 'deployer')->where('resource_type', 'project')
                ->where('resource_id', $id)->where('status', 'active')->pluck('project_id')->unique();
            $project = $mapped->count() === 1
                ? CoreProject::query()->whereKey($mapped->first())->where('workspace_id', $workspace->getKey())->first() : null;
            abort_unless($project !== null, 404);
            abort_unless($actor === null || $this->projectAccess->canAccessProductResource($actor, $project, 'deployer'), 404);
            abort_unless(DeployerProject::query()->whereKey($id)->where('organization_id', $organization->getKey())->exists(), 404);
        }
    }

    private function permissions(array $permissions): array
    {
        $allowed = ['read', 'deploy', 'manage'];
        abort_if(array_diff($permissions, $allowed) !== [], 422, 'Invalid credential permissions.');

        return array_values(array_unique($permissions ?: ['read']));
    }
}
