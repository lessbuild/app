<?php

namespace App\Modules\Monitor\Services\Core;

use App\Core\Contracts\WorkspaceCredentialProvider;
use App\Core\Data\Credentials\WorkspaceCredential;
use App\Core\Data\Credentials\WorkspaceCredentialSnapshot;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project as CoreProject;
use App\Core\Models\ProjectResource;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Core\Services\Identity\ProductWorkspaceAccess;
use App\Core\Services\LegacyIdentityResolver;
use App\Core\Services\PlatformProductRouteLinks;
use App\Core\Services\WorkspaceProjectAccess;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\IngestToken;
use App\Modules\Monitor\Models\Monitor;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Project-mapped Monitor keys stay owned by Monitor and never cross Core as hashes. */
final class MonitorWorkspaceCredentialProvider implements WorkspaceCredentialProvider
{
    public function __construct(
        private readonly MonitorProjectLink $projectLinks,
        private readonly WorkspaceProjectAccess $projectAccess,
        private readonly ProductWorkspaceAccess $workspaceAccess,
        private readonly LegacyIdentityResolver $identities,
        private readonly PlatformProductRouteLinks $productLinks,
    ) {}

    /**
     * @param  Collection<int, CoreProject>  $projects
     */
    public function credentialsForWorkspace(
        PlatformUser $user,
        CoreWorkspace $workspace,
        Collection $projects,
        int $limit,
    ): WorkspaceCredentialSnapshot {
        if ($projects->isEmpty()) {
            return new WorkspaceCredentialSnapshot(collect());
        }

        try {
            $mappedEnvironments = $this->credentialEnvironments($user, $workspace, $projects);
            if ($mappedEnvironments === []) {
                return new WorkspaceCredentialSnapshot(collect());
            }

            if (! Schema::connection('monitor')->hasTable('ingest_tokens')) {
                return new WorkspaceCredentialSnapshot(collect(), available: false);
            }

            $workspaceIds = collect($mappedEnvironments)
                ->map(fn (array $mapping): string => (string) $mapping['workspace_id'])
                ->unique()
                ->values()
                ->all();
            $managerWorkspaceIds = $this->managerWorkspaceIds($user, $workspaceIds);
            $mappedEnvironments = array_filter(
                $mappedEnvironments,
                fn (array $mapping): bool => in_array((string) $mapping['workspace_id'], $managerWorkspaceIds, true),
            );
            if ($mappedEnvironments === []) {
                return new WorkspaceCredentialSnapshot(collect());
            }

            $limit = max(1, min(100, $limit));
            $credentials = IngestToken::query()
                ->whereIn('environment_id', array_keys($mappedEnvironments))
                ->with(['environment:id,application_id,name', 'environment.application:id,workspace_id'])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit($limit)
                ->get(['id', 'environment_id', 'name', 'prefix', 'expires_at', 'revoked_at', 'last_used_at', 'created_at'])
                ->map(function (IngestToken $token) use ($mappedEnvironments): ?WorkspaceCredential {
                    $mapping = $mappedEnvironments[(string) $token->environment_id] ?? null;
                    $environment = $mapping['environment'] ?? null;
                    $application = $environment?->application;
                    if ($mapping === null || $environment === null || $application === null) {
                        return null;
                    }

                    $status = $token->status();
                    $manageUrl = $this->productLinks->to('monitor', 'monitor.environments.show', [
                        'application' => $application->getKey(),
                        'environment' => $environment->getKey(),
                    ]);

                    return new WorkspaceCredential(
                        key: 'monitor:ingest-token:'.$token->getKey(),
                        product: 'monitor',
                        productLabel: (string) config('platform.products.monitor.label', __('Monitor')),
                        type: __('Monitor ingestion token'),
                        name: (string) $token->name,
                        scope: __(':project · :environment', [
                            'project' => $mapping['project']->name,
                            'environment' => $mapping['label'],
                        ]),
                        status: $status,
                        statusLabel: __(ucfirst($status)),
                        prefix: (string) $token->prefix,
                        createdAt: $token->created_at?->toImmutable()->utc(),
                        lastUsedAt: $token->last_used_at?->toImmutable()->utc(),
                        expiresAt: $token->expires_at?->toImmutable()->utc(),
                        manageUrl: $manageUrl,
                    );
                })
                ->filter()
                ->values();

            if (Schema::connection('monitor')->hasTable('monitors')
                && Schema::connection('monitor')->hasColumn('monitors', 'queue_token_hash')
                && Schema::connection('monitor')->hasColumn('monitors', 'heartbeat_token_hash')) {
                $monitorCredentials = Monitor::query()
                    ->whereIn('environment_id', array_keys($mappedEnvironments))
                    ->where(fn ($query) => $query
                        ->where(fn ($queue) => $queue->where('type', 'queue')->whereNotNull('queue_token_hash'))
                        ->orWhere(fn ($heartbeat) => $heartbeat->where('type', 'heartbeat')->whereNotNull('heartbeat_token_hash')))
                    ->orderByDesc('id')
                    ->limit($limit)
                    ->get(['id', 'environment_id', 'name', 'type']);

                $credentials = $credentials->concat($monitorCredentials->map(function (Monitor $monitor) use ($mappedEnvironments): ?WorkspaceCredential {
                    $mapping = $mappedEnvironments[(string) $monitor->environment_id] ?? null;
                    $environment = $mapping['environment'] ?? null;
                    if ($mapping === null || $environment === null) {
                        return null;
                    }

                    // Presence is checked by the source query; Core never loads or receives either hash.
                    $isQueue = $monitor->type === 'queue';
                    $isHeartbeat = $monitor->type === 'heartbeat';
                    if (! $isQueue && ! $isHeartbeat) {
                        return null;
                    }

                    $workspaceId = (string) $mapping['workspace_id'];

                    return new WorkspaceCredential(
                        key: 'monitor:'.($isQueue ? 'queue-key:' : 'heartbeat-key:').$monitor->getKey(),
                        product: 'monitor',
                        productLabel: (string) config('platform.products.monitor.label', __('Monitor')),
                        type: $isQueue ? __('Monitor queue key') : __('Monitor heartbeat key'),
                        name: (string) $monitor->name,
                        scope: __(':project · :environment', [
                            'project' => $mapping['project']->name,
                            'environment' => $mapping['label'],
                        ]),
                        status: 'active',
                        statusLabel: __('Active'),
                        prefix: null,
                        createdAt: null,
                        lastUsedAt: null,
                        expiresAt: null,
                        manageUrl: $this->productLinks->to('monitor', 'monitor.monitors.show', [
                            'monitor' => $monitor->getKey(),
                            'workspace_id' => $workspaceId,
                        ]),
                    );
                })->filter()->values())->take($limit)->values();
            }

            return new WorkspaceCredentialSnapshot($credentials);
        } catch (LostConnectionException|QueryException) {
            return new WorkspaceCredentialSnapshot(collect(), available: false);
        }
    }

    /**
     * Resolve credential scopes only through one unambiguous Core environment mapping.
     *
     * @param  Collection<int, CoreProject>  $projects
     * @return array<string, array{project: CoreProject, environment: Environment, label: string, workspace_id: int}>
     */
    private function credentialEnvironments(PlatformUser $user, CoreWorkspace $workspace, Collection $projects): array
    {
        $candidates = [];
        $ambiguous = [];

        foreach ($projects as $project) {
            if ((string) $project->workspace_id !== (string) $workspace->getKey()
                || ! $this->projectAccess->canAccessProductResource($user, $project, 'monitor')) {
                continue;
            }

            $resources = ProjectResource::query()
                ->where('project_id', $project->getKey())
                ->where('product', 'monitor')
                ->where('resource_type', 'environment')
                ->where('status', 'active')
                ->orderBy('id')
                ->with('environment:id,name')
                ->get(['id', 'project_id', 'product', 'resource_type', 'resource_id', 'status', 'name', 'environment_id']);

            foreach ($resources as $resource) {
                $environment = $this->projectLinks->accessibleEnvironment($user, $resource);
                $monitorWorkspace = $environment?->application?->workspace;

                if ($environment === null || $monitorWorkspace === null) {
                    continue;
                }

                $mappedWorkspaceId = $this->identities->canonicalIdForSource(
                    'monitor',
                    'workspace',
                    (string) $monitorWorkspace->getKey(),
                    'workspace',
                );
                if ($mappedWorkspaceId !== (string) $workspace->getKey()
                    || ! $this->workspaceAccess->allows($user, 'monitor', 'workspace', (string) $monitorWorkspace->getKey())) {
                    continue;
                }

                $environmentId = (string) $environment->getKey();
                if (isset($candidates[$environmentId]) || isset($ambiguous[$environmentId])) {
                    unset($candidates[$environmentId]);
                    $ambiguous[$environmentId] = true;

                    continue;
                }

                $candidates[$environmentId] = [
                    'project' => $project,
                    'environment' => $environment,
                    'label' => filled($resource->name) ? $resource->name : $environment->name,
                    'workspace_id' => (int) $monitorWorkspace->getKey(),
                ];
            }
        }

        return $candidates;
    }

    /** @param  list<string>  $workspaceIds
     * @return list<string>
     */
    private function managerWorkspaceIds(PlatformUser $user, array $workspaceIds): array
    {
        if ($workspaceIds === [] || ! Schema::connection('monitor')->hasTable('user_workspace')) {
            return [];
        }

        $legacyUserIds = $this->identities->sourceIdsFor($user, 'monitor');
        if (count($legacyUserIds) !== 1) {
            return [];
        }

        return DB::connection('monitor')->table('user_workspace')
            ->whereIn('workspace_id', $workspaceIds)
            ->where('user_id', $legacyUserIds[0])
            ->whereIn('role', ['owner', 'admin'])
            ->pluck('workspace_id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
    }
}
