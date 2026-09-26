<?php

namespace App\Core\Models;

use App\Core\Database\CoreModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WorkspaceFeatureRolloutChange extends CoreModel
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['previous_enabled' => 'boolean', 'enabled' => 'boolean'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'actor_user_id');
    }
}
