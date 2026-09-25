<?php

namespace App\Core\Services\Blueprints;

use App\Core\Data\Blueprints\BlueprintProductResult;
use App\Core\Data\Blueprints\BlueprintResource;
use App\Core\Data\Blueprints\BlueprintStepAttempt;
use App\Core\Exceptions\Blueprints\BlueprintBlocked;
use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\ProjectProduct;
use App\Core\Models\ProjectResource;
use Illuminate\Support\Str;

/** Called only within the Core acknowledgement transaction after source receipt verification. */
final class RecordBlueprintResources
{
    public function handle(BlueprintStepAttempt $attempt, BlueprintProductResult $result): array
    {
        $types = match ($attempt->product) {
            'deployer' => ['project', 'environment'],
            'monitor' => ['application', 'environment'],
            'analytics' => ['site'],
            default => [],
        };
        // Deployer permits up to 20 manual recipe references for each of 10 environments.
        if ($result->resources === [] || count($result->resources) > 40 || count($result->requirements) > 250) {
            throw new BlueprintBlocked('invalid_product_result');
        }
        $this->validateShape($attempt, $result);
        $seen = [];
        $safeResources = [];
        foreach ($result->resources as $resource) {
            if (! $resource instanceof BlueprintResource || ! in_array($resource->type, $types, true)
                || ! preg_match('/\A[A-Za-z0-9:_-]{1,191}\z/', $resource->sourceId)
                || ($resource->parentSourceId !== null && ! preg_match('/\A[A-Za-z0-9:_-]{1,191}\z/', $resource->parentSourceId))
                || ($resource->environmentKey !== null && ! isset($attempt->target->environments[$resource->environmentKey]))
                || ($resource->type === 'environment' && $resource->environmentKey === null)
                || isset($seen[$resource->type.'|'.$resource->sourceId])) {
                throw new BlueprintBlocked('invalid_product_result');
            }
            $seen[$resource->type.'|'.$resource->sourceId] = true;
            $environmentId = $resource->environmentKey === null ? null : $attempt->target->environments[$resource->environmentKey]['id'];
            if ($resource->environmentKey !== null && $environmentId === null) {
                throw new BlueprintBlocked('invalid_product_result');
            }
            $row = ProjectResource::query()->where('product', $attempt->product)->where('resource_type', $resource->type)
                ->where('resource_id', $resource->sourceId)->lockForUpdate()->first();
            if ($row !== null && ((string) $row->project_id !== $attempt->target->projectId || $row->environment_id !== $environmentId || $row->status !== 'active')) {
                throw new BlueprintBlocked('resource_conflict');
            }
            $name = $this->text($resource->name);
            $metadata = ['blueprint_run_id' => $attempt->runId, 'source_product' => $attempt->product];
            if ($resource->parentSourceId !== null) {
                $metadata['source_application_id'] = $resource->parentSourceId;
            }
            $row ??= ProjectResource::query()->create([
                'project_id' => $attempt->target->projectId, 'environment_id' => $environmentId, 'product' => $attempt->product,
                'resource_type' => $resource->type, 'resource_id' => $resource->sourceId, 'name' => $name,
                'status' => 'active', 'metadata' => $metadata, 'mapped_at' => now(),
            ]);
            $entity = $resource->type === 'environment' ? 'project_environment' : 'project';
            $canonicalId = $entity === 'project_environment' ? $environmentId : $attempt->target->projectId;
            $identity = LegacyIdentityMap::query()->where('source_product', $attempt->product)->where('source_entity', $resource->type)
                ->where('source_id', $resource->sourceId)->lockForUpdate()->first();
            if ($identity !== null && ($identity->status !== 'reconciled' || $identity->canonical_entity !== $entity || $identity->canonical_id !== $canonicalId)) {
                throw new BlueprintBlocked('resource_conflict');
            }
            $identity ??= LegacyIdentityMap::query()->create([
                'source_product' => $attempt->product, 'source_entity' => $resource->type, 'source_id' => $resource->sourceId,
                'canonical_entity' => $entity, 'canonical_id' => $canonicalId, 'status' => 'reconciled',
                'metadata' => [...$metadata, 'project_resource_id' => (string) $row->getKey()],
            ]);
            $safeResources[] = (new BlueprintResource($resource->type, $resource->sourceId, $name, $resource->environmentKey, $resource->parentSourceId))->toArray();
        }
        $product = ProjectProduct::query()->firstOrNew(['project_id' => $attempt->target->projectId, 'product' => $attempt->product]);
        $product->forceFill(['status' => 'active', 'requested_by_user_id' => $attempt->target->actorId,
            'activated_at' => $product->activated_at ?? now(), 'last_error_code' => null, 'last_error_at' => null])->save();

        return ['resources' => $safeResources, 'requirements' => array_map(fn (string $message): string => $this->text($message, 500), $result->requirements)];
    }

    private function text(string $text, int $limit = 180): string
    {
        return Str::limit(trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', strip_tags($text))), $limit);
    }

    private function validateShape(BlueprintStepAttempt $attempt, BlueprintProductResult $result): void
    {
        if ($attempt->product === 'analytics') {
            $keys = [];
            foreach ($result->resources as $resource) {
                if (! $resource instanceof BlueprintResource || $resource->type !== 'site' || $resource->environmentKey === null
                    || $resource->parentSourceId !== null || in_array($resource->environmentKey, $keys, true)) {
                    throw new BlueprintBlocked('invalid_product_result');
                }
                $keys[] = $resource->environmentKey;
            }
            if (count($keys) !== count($attempt->target->environments) || array_diff(array_keys($attempt->target->environments), $keys) !== []) {
                throw new BlueprintBlocked('invalid_product_result');
            }

            return;
        }
        if (! in_array($attempt->product, ['deployer', 'monitor'], true)) {
            return;
        }
        $rootType = $attempt->product === 'deployer' ? 'project' : 'application';
        $roots = array_values(array_filter($result->resources, fn ($resource): bool => $resource instanceof BlueprintResource && $resource->type === $rootType));
        if (count($roots) !== 1 || $roots[0]->environmentKey !== null) {
            throw new BlueprintBlocked('invalid_product_result');
        }
        $keys = [];
        foreach ($result->resources as $resource) {
            if ($resource instanceof BlueprintResource && $resource->type === 'environment') {
                if ($resource->parentSourceId !== $roots[0]->sourceId || $resource->environmentKey === null || in_array($resource->environmentKey, $keys, true)) {
                    throw new BlueprintBlocked('invalid_product_result');
                }
                $keys[] = $resource->environmentKey;
            }
        }
        if (count($keys) !== count($attempt->target->environments) || array_diff(array_keys($attempt->target->environments), $keys) !== []) {
            throw new BlueprintBlocked('invalid_product_result');
        }
    }
}
