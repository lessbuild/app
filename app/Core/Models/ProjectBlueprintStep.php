<?php

namespace App\Core\Models;

use App\Core\Data\Blueprints\BlueprintStepAttempt;
use App\Core\Data\Blueprints\BlueprintTarget;
use App\Core\Database\CoreModel;
use LogicException;

class ProjectBlueprintStep extends CoreModel
{
    protected $guarded = [];

    protected $hidden = ['lease_token', 'native_authority'];

    protected function casts(): array
    {
        return ['target' => 'array', 'configuration' => 'array', 'native_authority' => 'array', 'result' => 'array', 'generation' => 'integer', 'lease_expires_at' => 'datetime', 'available_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $step): void {
            foreach (['project_blueprint_run_id', 'product', 'target', 'configuration', 'native_authority', 'core_binding_hash', 'payload_hash'] as $key) {
                if ($step->isDirty($key)) {
                    throw new LogicException('A blueprint product step is immutable.');
                }
            }
        });
    }

    public function attempt(): BlueprintStepAttempt
    {
        return new BlueprintStepAttempt((string) $this->project_blueprint_run_id, (string) $this->getKey(), $this->product,
            new BlueprintTarget(...$this->target), $this->configuration, $this->native_authority, $this->payload_hash,
            $this->generation, (string) $this->lease_token);
    }
}
