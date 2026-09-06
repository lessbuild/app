<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::creating(function ($model): void {
            if (! $model->organization_id && Auth::user()?->current_organization_id) {
                $model->organization_id = Auth::user()->current_organization_id;
            }
            if (! $model->organization_id && $model->user_id) {
                $model->organization_id = User::query()->whereKey($model->user_id)->value('current_organization_id');
            }
            if (! $model->user_id && Auth::id()) {
                $model->user_id = Auth::id();
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
