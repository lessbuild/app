<?php

namespace App\Core\Services\Restoration;

use App\Core\Data\Restoration\NativeRestorationReceipt;
use App\Core\Data\Restoration\ResourceRestorationTarget;
use App\Core\Exceptions\Restoration\ResourceRestorationBlocked;
use App\Core\Models\PlatformUser;
use App\Core\Models\ProjectResource;
use App\Core\Models\ResourceRestorationRequest;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RequestResourceRestoration
{
    public function __construct(private readonly ProductResourceRestorationRegistry $providers, private readonly ResourceRestorationAuthority $authority) {}

    public function request(PlatformUser $actor, ProjectResource $resource, string $idempotencyKey): ResourceRestorationRequest
    {
        if (! preg_match('/\A[A-Za-z0-9_-]{16,100}\z/', $idempotencyKey)) {
            throw ValidationException::withMessages(['idempotency_key' => __('Provide a valid restoration request key.')]);
        }
        // Both inspection and its native authorization finish before taking any Core lock.
        $target = $this->authority->target($actor, $resource);
        $provider = $this->providers->get($target->product, $target->resourceType);
        if ($provider === null) {
            throw new ResourceRestorationBlocked('restoration_unavailable');
        }
        $existing = ResourceRestorationRequest::query()->where('idempotency_key', $idempotencyKey)->first();
        $snapshot = $provider->inspect($actor, $target, $existing === null ? null : (string) $existing->getKey());
        if ($target->resourceType === 'environment') {
            if ($snapshot->parentApplicationId === null) {
                throw new ResourceRestorationBlocked('source_parent_mapping_changed');
            }
            $target = new ResourceRestorationTarget(...array_replace($target->toArray(), ['parentApplicationId' => $snapshot->parentApplicationId]));
        }
        $bindings = $this->authority->bindings($target, $snapshot->states, actorId: (string) $actor->getKey());
        $fingerprint = $this->authority->fingerprint($bindings);
        $states = array_map(fn ($state): array => $state->toArray(), $snapshot->states);
        usort($states, fn (array $left, array $right): int => [$left['resourceType'], $left['resourceId']] <=> [$right['resourceType'], $right['resourceId']]);
        $intent = [
            'actor' => (string) $actor->getKey(), 'target' => $target->toArray(), 'revision' => $snapshot->revision,
            'fingerprint' => $fingerprint, 'states' => $states,
        ];
        $hash = hash('sha256', json_encode($intent, JSON_THROW_ON_ERROR));
        if ($existing !== null) {
            return $this->reuse($existing, $hash, $intent, $bindings, $snapshot->currentReceipt);
        }
        try {
            return DB::connection('core')->transaction(function () use ($actor, $target, $snapshot, $fingerprint, $bindings, $states, $hash, $idempotencyKey): ResourceRestorationRequest {
                $this->authority->authorize($actor, $target, lock: true);
                $current = $this->authority->bindings($target, $snapshot->states, lock: true, actorId: (string) $actor->getKey());
                if (! hash_equals($fingerprint, $this->authority->fingerprint($current))) {
                    throw new ResourceRestorationBlocked('resource_mapping_changed');
                }

                return ResourceRestorationRequest::query()->create([
                    'actor_id' => $actor->getKey(), 'workspace_id' => $target->workspaceId,
                    'project_id' => $target->projectId, 'environment_id' => $target->environmentId,
                    'project_resource_id' => $target->projectResourceId, 'product' => $target->product,
                    'resource_type' => $target->resourceType, 'resource_id' => $target->resourceId,
                    'source_workspace_entity' => $target->sourceWorkspaceEntity, 'source_workspace_id' => $target->sourceWorkspaceId, 'source_parent_id' => $target->parentApplicationId,
                    'expected_revision' => $snapshot->revision, 'mapping_fingerprint' => $fingerprint,
                    'mapping_bindings' => $bindings, 'requested_states' => $states, 'payload_hash' => $hash,
                    'idempotency_key' => $idempotencyKey, 'status' => 'pending', 'attempts' => 0, 'available_at' => now(),
                ]);
            });
        } catch (UniqueConstraintViolationException $exception) {
            $existing = ResourceRestorationRequest::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing === null) {
                throw $exception;
            }

            return $this->reuse($existing, $hash, $intent, $bindings, $snapshot->currentReceipt);
        }
    }

    private function reuse(ResourceRestorationRequest $existing, string $hash, array $intent, array $bindings, ?NativeRestorationReceipt $receipt): ResourceRestorationRequest
    {
        if (hash_equals($existing->payload_hash, $hash)) {
            return $existing;
        }
        // A duplicate arriving after source commit has the receipt revision, not the
        // original revision. An exact current native receipt proves it is the same intent.
        if ($receipt !== null && $receipt->requestId === (string) $existing->getKey()
            && $receipt->revision === $intent['revision'] && $receipt->toArray()['states'] === $existing->requested_states
            && (($existing->status === 'completed' && $existing->receipt_revision === $receipt->revision && hash_equals((string) $existing->receipt_hash, $receipt->hash()))
                || ($existing->status !== 'completed' && hash_equals($existing->mapping_fingerprint, $intent['fingerprint'])))
            && $existing->actor_id === $intent['actor']
            && $existing->target()->toArray() === $intent['target']
            && $this->identities($existing->mapping_bindings) === $this->identities($bindings)) {
            return $existing;
        }

        throw ValidationException::withMessages(['idempotency_key' => __('This request key has already been used for a different restoration. Reload the archive page before trying again.')]);
    }

    private function identities(array $bindings): array
    {
        return array_map(function (?array $binding): ?array {
            if ($binding === null) {
                return null;
            }
            unset($binding['resource_status'], $binding['environment_status'], $binding['status'], $binding['resource_provenance']['archive_origin'], $binding['environment_provenance']['archive_origin']);
            if (array_key_exists('migration_source', $binding)) {
                unset($binding['archive_origin'], $binding['source_application_id']);
            }
            if (isset($binding['environment_resources'])) {
                $binding['environment_resources'] = array_map(function (array $resource): array {
                    unset($resource['status']);

                    return $resource;
                }, $binding['environment_resources']);
            }

            return $binding;
        }, $bindings);
    }
}
