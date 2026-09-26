<?php

namespace App\Core\Models;

use App\Core\Data\Deletion\ProductDeletionAttempt;
use App\Core\Data\Deletion\ProductDeletionTarget;
use App\Core\Database\CoreModel;
use LogicException;

class DeletionStep extends CoreModel
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['target' => 'array', 'retained' => 'array', 'attempts' => 'integer', 'lease_expires_at' => 'datetime', 'available_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $step): void {
            foreach (['deletion_request_id', 'product', 'kind', 'source_id', 'target', 'payload_hash'] as $key) {
                if ($step->isDirty($key)) {
                    throw new LogicException('A deletion step target is immutable.');
                }
            }
        });
    }

    public function attempt(): ProductDeletionAttempt
    {
        return new ProductDeletionAttempt((string) $this->deletion_request_id, (string) $this->getKey(), new ProductDeletionTarget(...$this->target), $this->payload_hash, $this->attempts, (string) $this->lease_token, $this->phase);
    }
}
