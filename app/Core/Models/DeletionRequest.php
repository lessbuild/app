<?php

namespace App\Core\Models;

use App\Core\Database\CoreModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class DeletionRequest extends CoreModel
{
    protected $guarded = [];

    protected $hidden = ['receipt_token_hash'];

    protected function casts(): array
    {
        return ['workspace_ids' => 'array', 'identity_bindings' => 'array', 'retained' => 'array', 'accepted_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $request): void {
            foreach (['actor_id', 'kind', 'target_id', 'workspace_ids', 'identity_bindings', 'intent_hash', 'receipt_token_hash', 'idempotency_key', 'accepted_at'] as $key) {
                if ($request->isDirty($key)) {
                    throw new LogicException('An accepted deletion intent is immutable.');
                }
            }
        });
    }

    public function steps(): HasMany
    {
        return $this->hasMany(DeletionStep::class);
    }
}
