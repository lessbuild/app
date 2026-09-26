<?php

namespace App\Core\Models;

use App\Core\Data\Restoration\NativeRestorationState;
use App\Core\Data\Restoration\ResourceRestorationAttempt;
use App\Core\Data\Restoration\ResourceRestorationTarget;
use App\Core\Database\CoreModel;
use LogicException;

class ResourceRestorationRequest extends CoreModel
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (self $request): void {
            foreach (['actor_id', 'product', 'resource_type', 'resource_id', 'project_resource_id', 'workspace_id', 'project_id', 'environment_id', 'source_workspace_entity', 'source_workspace_id', 'source_parent_id', 'expected_revision', 'mapping_fingerprint', 'mapping_bindings', 'requested_states', 'payload_hash', 'idempotency_key'] as $attribute) {
                if ($request->isDirty($attribute)) {
                    throw new LogicException('A restoration request intent is immutable.');
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'expected_revision' => 'integer', 'receipt_revision' => 'integer', 'attempts' => 'integer',
            'mapping_bindings' => 'array', 'requested_states' => 'array',
            'lease_expires_at' => 'datetime', 'available_at' => 'datetime',
            'last_attempted_at' => 'datetime', 'completed_at' => 'datetime', 'last_error_at' => 'datetime',
        ];
    }

    public function target(): ResourceRestorationTarget
    {
        return new ResourceRestorationTarget(
            $this->product, $this->resource_type, $this->resource_id, $this->project_resource_id,
            $this->workspace_id, $this->project_id, $this->environment_id,
            $this->source_workspace_entity, $this->source_workspace_id, $this->source_parent_id,
        );
    }

    public function attempt(): ResourceRestorationAttempt
    {
        return new ResourceRestorationAttempt(
            (string) $this->getKey(), $this->actor_id, $this->target(), $this->expected_revision,
            $this->mapping_fingerprint, $this->payload_hash, $this->attempts, (string) $this->lease_token,
        );
    }

    /** @return list<NativeRestorationState> */
    public function states(): array
    {
        return array_map(fn (array $state): NativeRestorationState => new NativeRestorationState(...$state), $this->requested_states);
    }
}
