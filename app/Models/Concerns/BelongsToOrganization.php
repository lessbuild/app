<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * @phpstan-require-extends Model
 */
trait BelongsToOrganization
{
    /** Assign missing workspace ownership when a new model is created. */
    public static function bootBelongsToOrganization(): void
    {
        static::creating(function (Model $model): void {
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

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
