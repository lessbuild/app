<?php

namespace App\Modules\Monitor\Services\Core;

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
use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\IngestToken;
use App\Modules\Monitor\Models\Monitor;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use App\Modules\Monitor\Services\CreateIngestToken;
use App\Modules\Monitor\Services\RevokeIngestToken;
use App\Modules\Monitor\Services\RotateHeartbeatToken;
use App\Modules\Monitor\Services\RotateIngestToken;
use App\Modules\Monitor\Services\RotateQueueToken;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Issues Monitor secrets only through Monitor's existing hashed-key services. */
final class MonitorWorkspaceCredentialMutationProvider implements WorkspaceCredentialMutationProvider
{
    public function __construct(
        private readonly MonitorProjectLink $projectLinks,
        private readonly WorkspaceProjectAccess $projectAccess,
        private readonly ProductWorkspaceAccess $workspaceAccess,
        private readonly LegacyIdentityResolver $identities,
    ) {}

    public function createOptions(PlatformUser $actor, CoreWorkspace $workspace, Collection $projects): CredentialCreateOptions
    {
        [$user, $workspaceIds] = $this->resolveActorAndWorkspaces($actor, $workspace);
        if (! $user || count($workspaceIds) !== 1) {
            return new CredentialCreateOptions(collect());
        }
        $options = collect();
        foreach ($projects as $project) {
            if ((string) $project->workspace_id !== (string) $workspace->getKey() || ! $this->projectAccess->canAccessProductResource($actor, $project, 'monitor')) {
                continue;
            }
            $resources = ProjectResource::query()->where('project_id', $project->getKey())->where('product', 'monitor')
                ->where('resource_type', 'environment')->where('status', 'active')->get();
            foreach ($resources as $resource) {
                $environment = $this->projectLinks->accessibleEnvironment($actor, $resource);
                $nativeWorkspace = $environment?->application?->workspace;
                if (! $environment || ! $nativeWorkspace || ! in_array((string) $nativeWorkspace->getKey(), $workspaceIds, true)
                    || ! $this->manager($actor, $user, $nativeWorkspace)) {
                    continue;
                }
                $label = __(':project · :environment', ['project' => $project->name, 'environment' => filled($resource->name) ? $resource->name : $environment->name]);
                $options->push(new CredentialCreateOption('monitor', 'ingest-token', __('Monitor ingestion token'), 'monitor:environment:'.$environment->getKey(), $label, [], true, true));
                if (! $user->hasVerifiedEmail()) {
                    continue;
                }
                foreach (Monitor::query()->where('environment_id', $environment->getKey())->whereIn('type', ['queue', 'heartbeat'])->get(['id', 'type', 'queue_token_hash', 'heartbeat_token_hash', 'name']) as $monitor) {
                    $hash = $monitor->type === 'queue' ? $monitor->queue_token_hash : $monitor->heartbeat_token_hash;
                    if ($hash === null) {
                        $type = $monitor->type === 'queue' ? 'queue-key' : 'heartbeat-key';
                        $options->push(new CredentialCreateOption('monitor', $type, $monitor->name, 'monitor:monitor:'.$monitor->getKey(), $label, [], false, false));
                    }
                }
            }
        }

        return new CredentialCreateOptions($options->unique(fn (CredentialCreateOption $option): string => $option->type.'|'.$option->targetKey)->values());
    }

    public function mutate(PlatformUser $actor, CoreWorkspace $workspace, CredentialMutationCommand $command): CredentialMutationOutcome
    {
        abort_unless($command->product === 'monitor', 404);
        abort_unless($command->permissions === [] && $command->canonicalProjectIds === [], 422, 'This Monitor credential type does not accept Deployer permission or project scopes.');
        [$actorIds, $workspaceIds] = $this->sourceIds($actor, $workspace);
        abort_unless(count($actorIds) === 1 && count($workspaceIds) === 1, 404, 'Monitor identity mapping is unavailable.');
        $actorId = $actorIds[0];

        return DB::connection('monitor')->transaction(function () use ($actor, $workspace, $command, $actorId, $workspaceIds): CredentialMutationOutcome {
            $user = User::query()->lockForUpdate()->findOrFail($actorId);
            // Preserve native ingestion-token access; check-token changes use verified routes.
            abort_unless($command->credentialType === 'ingest-token' || $user->hasVerifiedEmail(), 403);
            MonitorDeletionFence::lockUser($actorId);
            abort_if(MonitorDeletionFence::userIsFenced($actorId), 410, 'Credential changes are unavailable during deletion.');
            $previous = DB::connection('monitor')->table('credential_mutation_receipts')->where('operation_id', $command->operationId)->lockForUpdate()->first();
            if ($previous !== null) {
                abort_unless((string) $previous->actor_source_id === (string) $actorId
                    && (string) $previous->workspace_source_id === (string) $workspaceIds[0]
                    && $previous->action === $command->action
                    && hash_equals((string) $previous->input_hash, $command->inputHash), 409, 'Credential operation receipt conflicts with this request.');
                $this->assertCredentialReceiptAuthority($actor, $workspace, $user, $workspaceIds, (string) $previous->credential_key);
                $this->assertCurrentIdentity($actor, $workspace, $user, $workspaceIds);

                return new CredentialMutationOutcome($previous->action === 'revoke' ? 'revoked' : 'secret_unavailable', (string) $previous->credential_key, (string) $previous->credential_type, (string) $previous->credential_name);
            }
            abort_unless(! $command->replayOnly, 409, 'The native credential receipt is unavailable; the operation was not repeated.');

            [$action, $id, $type, $name, $secret] = $this->applyMutation($actor, $workspace, $user, $command, $workspaceIds);
            DB::connection('monitor')->table('credential_mutation_receipts')->insert([
                'operation_id' => $command->operationId, 'actor_source_id' => $actorId,
                'workspace_source_id' => $this->receiptWorkspaceId($command, $workspaceIds), 'action' => $action,
                'input_hash' => $command->inputHash, 'credential_key' => 'monitor:'.$type.':'.$id,
                'credential_type' => $type, 'credential_name' => $name, 'created_at' => now(),
            ]);
            $this->assertCredentialReceiptAuthority($actor, $workspace, $user, $workspaceIds, 'monitor:'.$type.':'.$id);
            $this->assertCurrentIdentity($actor, $workspace, $user, $workspaceIds);

            return new CredentialMutationOutcome($action === 'revoke' ? 'revoked' : 'issued', 'monitor:'.$type.':'.$id, $type, $name, $secret);
        }, attempts: 3);
    }

    private function applyMutation(PlatformUser $actor, CoreWorkspace $workspace, User $user, CredentialMutationCommand $command, array $workspaceIds): array
    {
        if ($command->action === 'create') {
            if ($command->credentialType === 'ingest-token') {
                abort_unless($command->expiresInDays === null || ($command->expiresInDays >= 1 && $command->expiresInDays <= 365), 422, 'Invalid expiration period.');
                abort_unless(filled($command->name) && mb_strlen((string) $command->name) <= 120, 422, 'A token name of at most 120 characters is required.');
                abort_unless(preg_match('/^monitor:environment:(\d+)$/', (string) $command->targetKey, $m) === 1, 404);
                $environment = Environment::query()->whereKey($m[1])->where('status', 'active')->firstOrFail();
                $application = Application::query()->lockForUpdate()->findOrFail($environment->application_id);
                $nativeWorkspace = Workspace::query()->lockForUpdate()->findOrFail($application->workspace_id);
                $environment = Environment::query()->lockForUpdate()->findOrFail($environment->getKey());
                $this->assertWorkspaceScope($actor, $workspace, $nativeWorkspace, $environment, $workspaceIds);
                Gate::forUser($user)->authorize('update', $environment);
                $expiresAt = $command->expiresInDays !== null ? now()->addDays($command->expiresInDays) : null;
                $issued = app(CreateIngestToken::class)->create($environment, $user, (string) $command->name, $expiresAt);

                return ['create', (string) $issued->token->getKey(), 'ingest-token', (string) $issued->token->name, $issued->secret];
            }
            if (in_array($command->credentialType, ['queue-key', 'heartbeat-key'], true)) {
                abort_unless($command->expiresInDays === null, 422, 'This credential type does not support expiration.');
                abort_unless(preg_match('/^monitor:monitor:(\d+)$/', (string) $command->targetKey, $m) === 1, 404);
                $monitorRef = Monitor::query()->with('environment.application')->findOrFail($m[1]);
                $nativeWorkspace = Workspace::query()->lockForUpdate()->findOrFail($monitorRef->environment->application->workspace_id);
                $monitor = Monitor::query()->whereKey($m[1])->lockForUpdate()->firstOrFail();
                $monitor->setRelation('environment', $monitorRef->environment);
                $monitor->environment->setRelation('application', $monitorRef->environment->application);
                $this->assertWorkspaceScope($actor, $workspace, $nativeWorkspace, $monitor->environment, $workspaceIds);
                abort_unless($monitor->type === ($command->credentialType === 'queue-key' ? 'queue' : 'heartbeat'), 404);
                abort_unless(($command->credentialType === 'queue-key' ? $monitor->queue_token_hash : $monitor->heartbeat_token_hash) === null, 409, 'This key already exists.');
                $version = (int) $monitor->state_version;
                $secret = $command->credentialType === 'queue-key'
                    ? app(RotateQueueToken::class)->change($nativeWorkspace, $user, $monitor, $version)
                    : app(RotateHeartbeatToken::class)->change($nativeWorkspace, $user, $monitor, $version);
                abort_if($secret === null, 409, 'The key could not be issued.');

                return ['create', (string) $monitor->getKey(), $command->credentialType, (string) $monitor->name, $secret];
            }
        }
        abort_unless(in_array($command->action, ['rotate', 'revoke'], true), 422, 'Unsupported credential operation.');
        abort_unless(preg_match('/^monitor:(ingest-token|queue-key|heartbeat-key):(\d+)$/', (string) $command->credentialKey, $m) === 1, 404);
        [, $type, $id] = $m;
        if ($type === 'ingest-token') {
            $tokenRef = IngestToken::query()->whereKey($id)->firstOrFail();
            $environmentRef = Environment::query()->findOrFail($tokenRef->environment_id);
            $application = Application::query()->lockForUpdate()->findOrFail($environmentRef->application_id);
            $nativeWorkspace = Workspace::query()->lockForUpdate()->findOrFail($application->workspace_id);
            $environment = Environment::query()->lockForUpdate()->findOrFail($environmentRef->getKey());
            $token = $environment->ingestTokens()->lockForUpdate()->findOrFail($id);
            $this->assertWorkspaceScope($actor, $workspace, $nativeWorkspace, $environment, $workspaceIds);
            Gate::forUser($user)->authorize('update', $environment);
            if ($command->action === 'revoke') {
                app(RevokeIngestToken::class)->revoke($token, $user, $environment, $application);

                return ['revoke', (string) $id, $type, (string) $token->name, null];
            }
            abort_unless($token->status() === 'active', 409, 'Expired or revoked keys cannot be rotated. Create a replacement key instead.');
            $issued = app(RotateIngestToken::class)->rotate($token, $user);

            return ['rotate', (string) $issued->token->getKey(), $type, (string) $issued->token->name, $issued->secret];
        }
        $monitorRef = Monitor::query()->whereKey($id)->with('environment.application')->firstOrFail();
        $nativeWorkspace = Workspace::query()->lockForUpdate()->findOrFail($monitorRef->environment->application->workspace_id);
        $monitor = Monitor::query()->whereKey($id)->lockForUpdate()->firstOrFail();
        $monitor->setRelation('environment', $monitorRef->environment);
        $monitor->environment->setRelation('application', $monitorRef->environment->application);
        $this->assertWorkspaceScope($actor, $workspace, $nativeWorkspace, $monitor->environment, $workspaceIds);
        abort_unless(($type === 'queue-key' && $monitor->type === 'queue') || ($type === 'heartbeat-key' && $monitor->type === 'heartbeat'), 404);
        $version = (int) $monitor->state_version;
        if ($command->action === 'revoke') {
            if ($type === 'queue-key') {
                app(RotateQueueToken::class)->change($nativeWorkspace, $user, $monitor, $version, true);
            } else {
                app(RotateHeartbeatToken::class)->change($nativeWorkspace, $user, $monitor, $version, true);
            }

            return ['revoke', (string) $id, $type, (string) $monitor->name, null];
        }
        $secret = $type === 'queue-key'
            ? app(RotateQueueToken::class)->change($nativeWorkspace, $user, $monitor, $version)
            : app(RotateHeartbeatToken::class)->change($nativeWorkspace, $user, $monitor, $version);
        abort_if($secret === null, 409, 'The key could not be rotated.');

        return ['rotate', (string) $id, $type, (string) $monitor->name, $secret];
    }

    private function resolveActorAndWorkspaces(PlatformUser $actor, CoreWorkspace $workspace): array
    {
        [$users, $workspaces] = $this->sourceIds($actor, $workspace);
        if (count($users) !== 1 || count($workspaces) !== 1) {
            return [null, []];
        }
        $user = User::query()->find($users[0]);
        $managed = $user ? Workspace::query()->whereIn('id', $workspaces)->whereHas('members', fn ($q) => $q->where('users.id', $user->getKey())->whereIn('role', ['owner', 'admin']))->pluck('id')->map(fn ($id) => (string) $id)->all() : [];

        return [$user, $managed];
    }

    private function sourceIds(PlatformUser $actor, CoreWorkspace $workspace): array
    {
        return [$this->identities->sourceIdsFor($actor, 'monitor'), $this->identities->sourceIdsForCanonical('monitor', 'workspace', (string) $workspace->getKey(), 'workspace')];
    }

    private function manager(PlatformUser $actor, User $user, Workspace $workspace): bool
    {
        return $workspace->members()->where('users.id', $user->getKey())->wherePivotIn('role', ['owner', 'admin'])->exists()
            && $this->workspaceAccess->allows($actor, 'monitor', 'workspace', (string) $workspace->getKey());
    }

    private function assertWorkspaceScope(PlatformUser $actor, CoreWorkspace $canonical, Workspace $native, Environment $environment, array $workspaceIds): void
    {
        abort_unless(in_array((string) $native->getKey(), $workspaceIds, true)
            && $this->identities->canonicalIdForSource('monitor', 'workspace', (string) $native->getKey(), 'workspace') === (string) $canonical->getKey()
            && $this->workspaceAccess->allows($actor, 'monitor', 'workspace', (string) $native->getKey()), 404);
        $mappings = ProjectResource::query()->where('product', 'monitor')->where('resource_type', 'environment')
            ->where('resource_id', (string) $environment->getKey())->where('status', 'active')->get(['project_id']);
        $accessible = $mappings->filter(function (ProjectResource $resource) use ($actor, $canonical): bool {
            $project = CoreProject::query()->whereKey($resource->project_id)->where('workspace_id', $canonical->getKey())->where('status', 'active')->first();

            return $project !== null && $this->projectAccess->canAccessProductResource($actor, $project, 'monitor');
        });
        abort_unless($mappings->count() === 1 && $accessible->count() === 1, 404, 'The Monitor resource is no longer mapped to one accessible Core project.');
        abort_if(MonitorDeletionFence::lockWorkspace($native->getKey()) || MonitorDeletionFence::canonicalWorkspaceIsFenced((string) $canonical->getKey()), 410, 'Credential changes are unavailable during deletion.');
    }

    private function receiptWorkspaceId(CredentialMutationCommand $command, array $workspaceIds): string
    {
        return (string) ($workspaceIds[0] ?? '');
    }

    private function assertCurrentIdentity(PlatformUser $actor, CoreWorkspace $workspace, User $user, array $workspaceIds): void
    {
        [$currentUsers, $currentWorkspaces] = $this->sourceIds($actor, $workspace);
        abort_unless(count($currentUsers) === 1 && (string) $currentUsers[0] === (string) $user->getKey()
            && $currentWorkspaces === $workspaceIds
            && $this->workspaceAccess->allows($actor, 'monitor', 'workspace', $workspaceIds[0]), 404);
    }

    private function assertCredentialReceiptAuthority(PlatformUser $actor, CoreWorkspace $canonical, User $user, array $workspaceIds, string $credentialKey): void
    {
        abort_unless(preg_match('/^monitor:(ingest-token|queue-key|heartbeat-key):(\d+)$/', $credentialKey, $matches) === 1, 409);
        [, $type, $id] = $matches;
        if ($type === 'ingest-token') {
            $tokenRef = IngestToken::query()->whereKey($id)->firstOrFail();
            $environmentRef = Environment::query()->findOrFail($tokenRef->environment_id);
            $application = Application::query()->lockForUpdate()->findOrFail($environmentRef->application_id);
            $nativeWorkspace = Workspace::query()->lockForUpdate()->findOrFail($application->workspace_id);
            $environment = Environment::query()->lockForUpdate()->findOrFail($environmentRef->getKey());
            $this->assertWorkspaceScope($actor, $canonical, $nativeWorkspace, $environment, $workspaceIds);
            Gate::forUser($user)->authorize('update', $environment);

            return;
        }

        $monitorRef = Monitor::query()->whereKey($id)->with('environment.application')->firstOrFail();
        $nativeWorkspace = Workspace::query()->lockForUpdate()->findOrFail($monitorRef->environment->application->workspace_id);
        $monitor = Monitor::query()->whereKey($id)->lockForUpdate()->firstOrFail();
        $monitor->setRelation('environment', $monitorRef->environment);
        $monitor->environment->setRelation('application', $monitorRef->environment->application);
        abort_unless(($type === 'queue-key' && $monitor->type === 'queue') || ($type === 'heartbeat-key' && $monitor->type === 'heartbeat'), 404);
        $this->assertWorkspaceScope($actor, $canonical, $nativeWorkspace, $monitor->environment, $workspaceIds);
        Gate::forUser($user)->authorize('update', $nativeWorkspace);
        Gate::forUser($user)->authorize('update', $monitor);
    }
}
