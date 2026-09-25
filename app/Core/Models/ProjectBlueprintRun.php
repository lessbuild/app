<?php

namespace App\Core\Models;

use App\Core\Database\CoreModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class ProjectBlueprintRun extends CoreModel
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['environment_bindings' => 'array', 'completed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $run): void {
            foreach (['workspace_id', 'project_id', 'project_blueprint_version_id', 'requested_by_user_id', 'idempotency_key', 'intent_hash', 'environment_bindings'] as $key) {
                if ($run->isDirty($key)) {
                    throw new LogicException('An accepted blueprint application is immutable.');
                }
            }
        });
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ProjectBlueprintVersion::class, 'project_blueprint_version_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ProjectBlueprintStep::class)->orderBy('product');
    }
}
