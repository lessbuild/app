<?php

namespace App\Modules\Deployer\Services;

use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\ProductDeletionActivityClaim;
use App\Modules\Deployer\Models\ProductDeletionFence;
use App\Modules\Deployer\Models\Recipe;
use App\Modules\Deployer\Models\RecipeReport;
use App\Modules\Deployer\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Durable short-lived activity claims that close the fence/preflight race. */
final class DeployerMutationClaimManager
{
    public function claimRequest(Request $request): ?string
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return null;
        }

        $workspaceIds = $this->workspaceIdsForRequest($request, $actor);

        return $this->claim($actor->getKey(), $workspaceIds, $request->route()?->getName() ?: 'web.mutation');
    }

    /** Claim untracked synchronous remote work before the job starts its first effect. */
    public function claimWorkspace(string|int $workspaceId, string $operation): ?string
    {
        $organization = Organization::query()->find($workspaceId);
        if (! $organization) {
            return null;
        }

        return $this->claim($organization->owner_id, [(string) $workspaceId], $operation, false, (string) $organization->owner_id);
    }

    public function complete(string $claimGroupId): void
    {
        ProductDeletionActivityClaim::query()->where('claim_group_id', $claimGroupId)->where('status', 'claimed')->update([
            'status' => 'completed', 'completed_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Keep a failed or crashed activity open until a human confirms it has stopped. */
    public function runWorkspace(string|int $workspaceId, string $operation, callable $work): mixed
    {
        $claimGroupId = $this->claimWorkspace($workspaceId, $operation);
        if ($claimGroupId === null) {
            return null;
        }

        try {
            $result = $work();
            $this->complete($claimGroupId);

            return $result;
        } catch (Throwable $exception) {
            throw $exception;
        }
    }

    /** @param list<string|int> $workspaceIds */
    private function claim(string|int $actorId, array $workspaceIds, string $operation, bool $rejectFenced = true, ?string $expectedOwnerId = null): ?string
    {
        $workspaceIds = array_values(array_unique(array_map('strval', $workspaceIds)));
        sort($workspaceIds);
        $groupId = (string) Str::uuid();

        return DB::connection('deployer')->transaction(function () use ($actorId, $workspaceIds, $operation, $rejectFenced, $groupId, $expectedOwnerId): ?string {
            $actorId = (string) $actorId;
            $this->reserveRow('users', $actorId);
            foreach ($workspaceIds as $workspaceId) {
                $this->reserveRow('organizations', $workspaceId);
                if ($expectedOwnerId !== null && (string) Organization::query()->whereKey($workspaceId)->value('owner_id') !== $expectedOwnerId) {
                    return null;
                }
            }

            $fenced = ProductDeletionFence::query()->where('kind', 'account')->where('source_id', $actorId)->exists()
                || ($workspaceIds !== [] && ProductDeletionFence::query()->where('kind', 'workspace')->whereIn('source_id', $workspaceIds)->exists());
            if ($fenced) {
                if ($rejectFenced) {
                    abort(409, 'This Deployer account or workspace is being deleted.');
                }

                return null;
            }

            $scopes = $workspaceIds === [] ? [null] : $workspaceIds;
            foreach ($scopes as $workspaceId) {
                ProductDeletionActivityClaim::query()->create([
                    'id' => (string) Str::uuid(),
                    'claim_group_id' => $groupId,
                    'actor_source_id' => $actorId,
                    'workspace_source_id' => $workspaceId,
                    'operation' => mb_substr($operation, 0, 96),
                    'status' => 'claimed',
                    'started_at' => now(),
                ]);
            }

            return $groupId;
        });
    }

    private function reserveRow(string $table, string $id): void
    {
        $db = DB::connection('deployer');
        $updated = $db->table($table)->where('id', $id)->update(['id' => DB::raw('id')]);
        if ($updated === 0 && ! $db->table($table)->where('id', $id)->exists()) {
            abort(409, 'The Deployer mutation target is no longer available.');
        }
        $db->table($table)->where('id', $id)->lockForUpdate()->first();
    }

    /** @return list<string> */
    private function workspaceIdsForRequest(Request $request, User $actor): array
    {
        $workspaceIds = [];
        $unresolvedScope = false;
        $actorOnlyTarget = false;
        foreach ($request->route()?->parameters() ?? [] as $name => $value) {
            if (! $this->mayIdentifyWorkspace($name) && ! $value instanceof Model) {
                continue;
            }
            if ($value instanceof Organization) {
                $workspaceIds[] = (string) $value->getKey();

                continue;
            }
            if (! $value instanceof Model) {
                if ($this->mayIdentifyWorkspace($name)) {
                    $unresolvedScope = true;
                }

                continue;
            }

            $visited = [];
            $resolved = $this->workspaceIdsForModel($value, $visited);
            if ($resolved === [] && str_starts_with($value::class, 'App\\Modules\\Deployer\\Models\\')
                && ! $value instanceof User && ! $this->isActorOnlyModel($value)) {
                abort(409, 'The Deployer mutation workspace could not be resolved safely.');
            }
            $actorOnlyTarget = $actorOnlyTarget || ($resolved === [] && $this->isActorOnlyModel($value));
            array_push($workspaceIds, ...$resolved);
        }

        $workspaceIds = array_values(array_unique($workspaceIds));
        if ($unresolvedScope && $workspaceIds === []) {
            abort(409, 'The Deployer mutation workspace could not be resolved safely.');
        }
        if ($workspaceIds === [] && ! $actorOnlyTarget) {
            $current = $actor->currentOrganization;
            if ($current instanceof Organization) {
                $workspaceIds[] = (string) $current->getKey();
            }
        }

        return $workspaceIds;
    }

    private function mayIdentifyWorkspace(string $parameter): bool
    {
        return in_array(strtolower($parameter), [
            'organization', 'workspace', 'server', 'website', 'repository', 'project', 'environment', 'build',
            'provider', 'recipe', 'domain', 'loadbalancer', 'resource', 'database', 'clone', 'task', 'review',
            'application', 'operation', 'backup', 'restore', 'verification', 'preview', 'statuspage', 'destination',
        ], true);
    }

    /** @return list<string> */
    private function workspaceIdsForModel(Model $model, array &$visited, int $depth = 0): array
    {
        if ($depth > 24) {
            abort(409, 'The Deployer mutation workspace could not be resolved safely.');
        }
        if ($model instanceof Organization) {
            return [(string) $model->getKey()];
        }
        $key = $model->getConnectionName().'|'.$model::class.'|'.$model->getKey();
        if (isset($visited[$key])) {
            return [];
        }
        $visited[$key] = true;

        $ids = [];
        $organizationId = $model->getAttribute('organization_id');
        if ($organizationId !== null) {
            $ids[] = (string) $organizationId;
        }
        foreach ([
            'organization', 'project', 'environment', 'repository', 'website', 'server', 'source', 'target', 'resource',
            'backup', 'websiteBackup', 'review', 'application', 'task', 'operation', 'preview', 'loadBalancer', 'build', 'recipe',
        ] as $relation) {
            if (! method_exists($model, $relation)) {
                continue;
            }
            try {
                $related = $model->getRelationValue($relation);
            } catch (Throwable) {
                abort(409, 'The Deployer mutation workspace could not be resolved safely.');
            }
            foreach (is_iterable($related) && ! ($related instanceof Model) ? $related : [$related] as $relatedModel) {
                if ($relatedModel instanceof Model && $relatedModel !== $model) {
                    array_push($ids, ...$this->workspaceIdsForModel($relatedModel, $visited, $depth + 1));
                }
            }
        }

        return array_values(array_unique($ids));
    }

    private function isActorOnlyModel(Model $model): bool
    {
        return in_array($model::class, [
            Recipe::class,
            RecipeReport::class,
        ], true);
    }
}
