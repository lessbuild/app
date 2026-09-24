<?php

namespace App\Core\Services\Identity;

use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Services\LegacyIdentityResolver;
use App\Modules\Analytics\Models\Workspace as AnalyticsWorkspace;
use App\Modules\Deployer\Models\Organization as DeployerOrganization;
use App\Modules\Monitor\Models\Workspace as MonitorWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Projects explicit Core product grants into the corresponding legacy workspace
 * membership tables. Role changes made here are tracked and restored when the
 * Core grant is revoked; legacy workspace ownership is always preserved.
 */
final class ProjectProductWorkspaceMembership
{
    /** @var array<string, array{entity:string, model:class-string<Model>, relation:string}> */
    private const WORKSPACES = [
        'deployer' => ['entity' => 'organization', 'model' => DeployerOrganization::class, 'relation' => 'members'],
        'monitor' => ['entity' => 'workspace', 'model' => MonitorWorkspace::class, 'relation' => 'members'],
        'analytics' => ['entity' => 'workspace', 'model' => AnalyticsWorkspace::class, 'relation' => 'users'],
    ];

    public function __construct(private readonly LegacyIdentityResolver $identities) {}

    /**
     * @param  array<string, array{managed?:bool,role?:string,created_by_projection?:bool,previous_role?:?string}>  $previous
     * @return array<string, array{managed:bool,role:string,created_by_projection:bool,previous_role:?string}>
     */
    public function grant(
        string $product,
        PlatformUser $user,
        Workspace $workspace,
        string $role,
        array $previous = [],
    ): array {
        $config = self::WORKSPACES[$product] ?? null;
        abort_if($config === null, 404);

        $principalIds = $this->identities->sourceIdsFor($user, $product);
        abort_unless(count($principalIds) === 1, 409, 'The product identity mapping needs explicit reconciliation.');

        $sourceWorkspaceIds = LegacyIdentityMap::query()
            ->where('source_product', $product)
            ->where('source_entity', $config['entity'])
            ->where('canonical_entity', 'workspace')
            ->where('canonical_id', (string) $workspace->getKey())
            ->where('status', 'reconciled')
            ->orderBy('source_id')
            ->pluck('source_id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        if ($sourceWorkspaceIds === []) {
            return [];
        }

        $principalId = $principalIds[0];
        $localRole = $this->localRole($product, $role);
        $previousAccess = $previous;

        return DB::connection($product)->transaction(function () use (
            $config,
            $localRole,
            $previousAccess,
            $principalId,
            $sourceWorkspaceIds,
        ): array {
            $projected = [];

            foreach ($sourceWorkspaceIds as $sourceWorkspaceId) {
                if (! ctype_digit($sourceWorkspaceId)) {
                    continue;
                }

                /** @var Model|null $productWorkspace */
                $productWorkspace = ($config['model'])::query()->find($sourceWorkspaceId);
                if ($productWorkspace === null) {
                    continue;
                }

                $relation = $productWorkspace->{$config['relation']}();
                $existing = $relation->whereKey($principalId)->first();
                $previousProjection = $previousAccess[$sourceWorkspaceId] ?? [];

                if ($existing !== null) {
                    $existingRole = (string) ($existing->pivot?->role ?? 'viewer');
                    if ($existingRole === 'owner') {
                        // Product ownership is source data and cannot be overwritten by a
                        // lower-privilege role selected in the shared workspace.
                        $projected[$sourceWorkspaceId] = [
                            'managed' => false,
                            'role' => $existingRole,
                            'created_by_projection' => false,
                            'previous_role' => null,
                        ];

                        continue;
                    }

                    $wasManaged = ($previousProjection['managed'] ?? false) === true
                        && $existingRole === (string) ($previousProjection['role'] ?? '');
                    $originalRole = $wasManaged
                        ? ($previousProjection['previous_role'] ?? null)
                        : $existingRole;
                    $wasCreated = $wasManaged && ($previousProjection['created_by_projection'] ?? false) === true;

                    if ($existingRole !== $localRole) {
                        $relation->updateExistingPivot($principalId, ['role' => $localRole]);
                    }

                    $projected[$sourceWorkspaceId] = [
                        'managed' => $existingRole !== $localRole || $wasManaged,
                        'role' => $localRole,
                        'created_by_projection' => $wasCreated,
                        'previous_role' => $originalRole,
                    ];

                    continue;
                }

                $relation->attach($principalId, ['role' => $localRole]);
                $projected[$sourceWorkspaceId] = [
                    'managed' => true,
                    'role' => $localRole,
                    'created_by_projection' => true,
                    'previous_role' => null,
                ];
            }

            return $projected;
        }, attempts: 3);
    }

    /** @param array<string, array{managed?:bool,role?:string,created_by_projection?:bool,previous_role?:?string}> $projections */
    public function revoke(string $product, string $principalId, array $projections): int
    {
        $config = self::WORKSPACES[$product] ?? null;
        abort_if($config === null, 404);

        $managedIds = collect($projections)
            ->filter(static fn (array $projection): bool => ($projection['managed'] ?? false) === true)
            ->keys()
            ->filter(static fn ($id): bool => ctype_digit((string) $id))
            ->values();

        if ($managedIds->isEmpty()) {
            return 0;
        }

        return DB::connection($product)->transaction(function () use ($config, $managedIds, $principalId, $projections): int {
            $removed = 0;

            foreach ($managedIds as $sourceWorkspaceId) {
                /** @var Model|null $productWorkspace */
                $productWorkspace = ($config['model'])::query()->find((string) $sourceWorkspaceId);
                if ($productWorkspace === null) {
                    continue;
                }

                $relation = $productWorkspace->{$config['relation']}();
                $existing = $relation->whereKey($principalId)->first();
                $projectedRole = (string) ($projections[(string) $sourceWorkspaceId]['role'] ?? '');

                // Never remove or rewrite a membership that has since been changed locally.
                if ($existing === null || $projectedRole === '' || (string) ($existing->pivot?->role ?? '') !== $projectedRole) {
                    continue;
                }

                $projection = $projections[(string) $sourceWorkspaceId];
                if (($projection['created_by_projection'] ?? false) === true) {
                    $relation->detach($principalId);
                    $removed++;
                } elseif (is_string($projection['previous_role'] ?? null) && $projection['previous_role'] !== '') {
                    $relation->updateExistingPivot($principalId, ['role' => $projection['previous_role']]);
                    $removed++;
                }
            }

            return $removed;
        }, attempts: 3);
    }

    private function localRole(string $product, string $role): string
    {
        return match ($product) {
            'deployer' => match ($role) {
                'admin' => 'admin',
                'member' => 'developer',
                default => 'viewer',
            },
            'monitor' => in_array($role, ['admin', 'member', 'viewer'], true) ? $role : 'viewer',
            'analytics' => $role === 'admin' ? 'admin' : 'viewer',
            default => 'viewer',
        };
    }
}
