<?php

namespace App\Modules\Monitor\Services\Core;

use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Core\Services\Identity\ProductWorkspaceAccess;
use App\Core\Services\LegacyIdentityResolver;
use App\Core\Services\WorkspaceProjectAccess;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/** Resolves one current Core identity/workspace pair to one exact Monitor source pair. */
final class MonitorAdministrationContext
{
    /** @var array<string, string> */
    private array $references = [];

    public function __construct(
        private readonly LegacyIdentityResolver $identities,
        private readonly WorkspaceProjectAccess $workspaceAccess,
        private readonly ProductWorkspaceAccess $productAccess,
    ) {}

    /** @return array{workspace: Workspace, user: User}|null */
    public function resolve(PlatformUser $user, CoreWorkspace $workspace): ?array
    {
        $membership = $this->workspaceAccess->activeMembership($user, $workspace);
        if ($membership === null || ! $this->workspaceAccess->hasProductAccess($membership, 'monitor')) {
            return null;
        }

        $sourceWorkspaceIds = $this->identities->sourceIdsForCanonical('monitor', 'workspace', (string) $workspace->getKey(), 'workspace');
        if (count($sourceWorkspaceIds) !== 1) {
            return null;
        }

        $sourceWorkspaceId = $sourceWorkspaceIds[0];
        if (! $this->productAccess->allows($user, 'monitor', 'workspace', $sourceWorkspaceId)) {
            return null;
        }

        $sourceWorkspace = Workspace::query()->find($sourceWorkspaceId);
        if ($sourceWorkspace === null
            || $this->identities->canonicalIdForSource('monitor', 'workspace', $sourceWorkspaceId, 'workspace') !== (string) $workspace->getKey()) {
            return null;
        }

        $sourceUserIds = $this->identities->sourceIdsFor($user, 'monitor');
        if (count($sourceUserIds) !== 1) {
            return null;
        }
        $memberIds = $sourceWorkspace->members()->whereIn('users.id', $sourceUserIds)->pluck('users.id')->map(strval(...))->all();
        if (count($memberIds) !== 1) {
            return null;
        }

        $sourceUser = User::query()->find($memberIds[0]);
        if ($sourceUser === null) {
            return null;
        }
        if (MonitorDeletionFence::workspaceIsFenced($sourceWorkspace->getKey())
            || MonitorDeletionFence::userIsFenced($sourceUser->getKey())) {
            return null;
        }

        Gate::forUser($sourceUser)->authorize('view', $sourceWorkspace);

        return ['workspace' => $sourceWorkspace, 'user' => $sourceUser];
    }

    public function reference(string $kind, string|int $id, Workspace $workspace): string
    {
        $cacheKey = implode(':', [$kind, (string) $workspace->getKey(), (string) $id]);
        if (isset($this->references[$cacheKey])) {
            return $this->references[$cacheKey];
        }

        return $this->references[$cacheKey] = Crypt::encryptString(json_encode([
            'kind' => $kind,
            'id' => (string) $id,
            'workspace' => (string) $workspace->getKey(),
        ], JSON_THROW_ON_ERROR));
    }

    public function resetReferences(): void
    {
        $this->references = [];
    }

    public function sourceId(string $reference, string $kind, Workspace $workspace): string
    {
        try {
            $payload = json_decode(Crypt::decryptString($reference), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            abort(404);
        }

        abort_unless(is_array($payload)
            && ($payload['kind'] ?? null) === $kind
            && ($payload['workspace'] ?? null) === (string) $workspace->getKey()
            && is_string($payload['id'] ?? null)
            && $payload['id'] !== '', 404);

        return $payload['id'];
    }

    public function mutate(PlatformUser $coreActor, CoreWorkspace $coreWorkspace, Workspace $workspace, User $actor, callable $mutation, bool $requiresManagement = true): mixed
    {
        return DB::connection('monitor')->transaction(function () use ($coreActor, $coreWorkspace, $workspace, $actor, $mutation, $requiresManagement): mixed {
            DB::connection('monitor')->table('users')->where('id', $actor->getKey())->update(['id' => DB::raw('id')]);
            MonitorDeletionFence::assertUserActive($actor->getKey());
            $lockedWorkspace = Workspace::query()->lockForUpdate()->findOrFail($workspace->getKey());
            MonitorDeletionFence::assertWorkspaceActive($lockedWorkspace->getKey());
            $this->assertCurrentPair($coreActor, $coreWorkspace, $actor, $lockedWorkspace, $requiresManagement);
            Gate::forUser($actor)->authorize($requiresManagement ? 'update' : 'view', $lockedWorkspace);

            $result = $mutation($lockedWorkspace);
            MonitorDeletionFence::assertUserActive($actor->getKey());
            MonitorDeletionFence::assertWorkspaceActive($lockedWorkspace->getKey());
            $this->assertCurrentPair($coreActor, $coreWorkspace, $actor, $lockedWorkspace, $requiresManagement);
            Gate::forUser($actor)->authorize($requiresManagement ? 'update' : 'view', $lockedWorkspace);

            return $result;
        }, attempts: 3);
    }

    private function assertCurrentPair(PlatformUser $coreActor, CoreWorkspace $coreWorkspace, User $actor, Workspace $sourceWorkspace, bool $requiresManagement): void
    {
        $membership = $this->workspaceAccess->activeMembership($coreActor, $coreWorkspace);
        $this->requireCurrent($coreWorkspace->status === 'active' && $membership !== null
            && $this->workspaceAccess->hasProductAccess($membership, 'monitor'), 404);
        $sourceUserIds = $this->identities->sourceIdsFor($coreActor, 'monitor');
        $sourceWorkspaceIds = $this->identities->sourceIdsForCanonical('monitor', 'workspace', (string) $coreWorkspace->getKey(), 'workspace');
        $this->requireCurrent(count($sourceUserIds) === 1 && $sourceUserIds[0] === (string) $actor->getKey()
            && count($sourceWorkspaceIds) === 1 && $sourceWorkspaceIds[0] === (string) $sourceWorkspace->getKey(), 404);
        $this->requireCurrent($sourceWorkspace->members()->whereKey($actor->getKey())->exists(), 404);
        if ($requiresManagement) {
            $this->requireCurrent(User::query()->whereKey($actor->getKey())->whereNotNull('email_verified_at')->exists(), 403);
        }
    }

    private function requireCurrent(bool $condition, int $status): void
    {
        abort_unless($condition, $status);
    }
}
