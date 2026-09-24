<?php

namespace App\Core\Services\Identity;

use App\Core\Contracts\ProductWorkspaceMembershipProjector;
use App\Core\Services\LegacyIdentityResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Shared projection mechanics; product modules provide their own model and role mapping. */
abstract class AbstractProductWorkspaceMembershipProjector implements ProductWorkspaceMembershipProjector
{
    public function __construct(protected readonly LegacyIdentityResolver $identities) {}

    /** @return class-string<Model> */
    abstract protected function workspaceModel(): string;

    abstract protected function workspaceEntity(): string;

    abstract protected function membershipRelation(): string;

    abstract protected function localRole(string $role): string;

    abstract protected function product(): string;

    public function grant(string $productPrincipalId, string $coreWorkspaceId, string $role, array $previous = []): array
    {
        $sourceWorkspaceIds = $this->identities->sourceIdsForCanonical(
            $this->product(),
            $this->workspaceEntity(),
            $coreWorkspaceId,
            'workspace',
        );

        if ($sourceWorkspaceIds === []) {
            return [];
        }

        $localRole = $this->localRole($role);
        $model = $this->workspaceModel();

        return DB::connection($this->product())->transaction(function () use (
            $localRole,
            $previous,
            $productPrincipalId,
            $sourceWorkspaceIds,
            $model,
        ): array {
            $projected = [];

            foreach ($sourceWorkspaceIds as $sourceWorkspaceId) {
                if (! ctype_digit($sourceWorkspaceId)) {
                    continue;
                }

                /** @var Model|null $productWorkspace */
                $productWorkspace = $model::query()->find($sourceWorkspaceId);
                if ($productWorkspace === null) {
                    continue;
                }

                $relation = $productWorkspace->{$this->membershipRelation()}();
                $existing = $relation->whereKey($productPrincipalId)->first();
                $previousProjection = $previous[$sourceWorkspaceId] ?? [];

                if ($existing !== null) {
                    $existingRole = (string) ($existing->pivot?->role ?? 'viewer');
                    if ($existingRole === 'owner') {
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
                        $relation->updateExistingPivot($productPrincipalId, ['role' => $localRole]);
                    }

                    $projected[$sourceWorkspaceId] = [
                        'managed' => $existingRole !== $localRole || $wasManaged,
                        'role' => $localRole,
                        'created_by_projection' => $wasCreated,
                        'previous_role' => $originalRole,
                    ];

                    continue;
                }

                $relation->attach($productPrincipalId, ['role' => $localRole]);
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

    public function revoke(string $productPrincipalId, array $projections): int
    {
        $managedIds = collect($projections)
            ->filter(static fn (array $projection): bool => ($projection['managed'] ?? false) === true)
            ->keys()
            ->filter(static fn ($id): bool => ctype_digit((string) $id))
            ->values();

        if ($managedIds->isEmpty()) {
            return 0;
        }

        $model = $this->workspaceModel();

        return DB::connection($this->product())->transaction(function () use (
            $managedIds,
            $productPrincipalId,
            $projections,
            $model,
        ): int {
            $removed = 0;

            foreach ($managedIds as $sourceWorkspaceId) {
                /** @var Model|null $productWorkspace */
                $productWorkspace = $model::query()->find((string) $sourceWorkspaceId);
                if ($productWorkspace === null) {
                    continue;
                }

                $relation = $productWorkspace->{$this->membershipRelation()}();
                $existing = $relation->whereKey($productPrincipalId)->first();
                $projection = $projections[(string) $sourceWorkspaceId];
                $projectedRole = (string) ($projection['role'] ?? '');

                // A local edit after projection belongs to the product user and is left intact.
                if ($existing === null || $projectedRole === '' || (string) ($existing->pivot?->role ?? '') !== $projectedRole) {
                    continue;
                }

                if (($projection['created_by_projection'] ?? false) === true) {
                    $relation->detach($productPrincipalId);
                    $removed++;
                } elseif (is_string($projection['previous_role'] ?? null) && $projection['previous_role'] !== '') {
                    $relation->updateExistingPivot($productPrincipalId, ['role' => $projection['previous_role']]);
                    $removed++;
                }
            }

            return $removed;
        }, attempts: 3);
    }
}
