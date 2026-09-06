<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConfigurationApplication extends Model
{
    protected $guarded = [];

    protected $casts = ['locally_applied_at' => 'datetime'];

    /** @return BelongsTo<ConfigurationReview, $this> */
    public function review(): BelongsTo
    {
        return $this->belongsTo(ConfigurationReview::class, 'configuration_review_id');
    }

    /** @return HasMany<ConfigurationOperation, $this> */
    public function operations(): HasMany
    {
        return $this->hasMany(ConfigurationOperation::class);
    }

    /** @return BelongsToMany<ConfigurationOperation, $this> */
    public function referencedOperations(): BelongsToMany
    {
        return $this->belongsToMany(ConfigurationOperation::class, 'configuration_operation_receipts');
    }

    /**
     * Query owned and reused operations without loading receipt rows into memory.
     *
     * @return Builder<ConfigurationOperation>
     */
    public function relatedOperations(): Builder
    {
        return ConfigurationOperation::query()->where(fn (Builder $query): Builder => $query
            ->where('configuration_application_id', $this->id)
            ->orWhereIn('id', $this->referencedOperations()->select('configuration_operations.id')));
    }
}
