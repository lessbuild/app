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

    public function review(): BelongsTo
    {
        return $this->belongsTo(ConfigurationReview::class, 'configuration_review_id');
    }

    public function operations(): HasMany
    {
        return $this->hasMany(ConfigurationOperation::class);
    }

    public function referencedOperations(): BelongsToMany
    {
        return $this->belongsToMany(ConfigurationOperation::class, 'configuration_operation_receipts');
    }

    public function relatedOperations(): Builder
    {
        return ConfigurationOperation::query()->where(fn ($query) => $query
            ->where('configuration_application_id', $this->id)
            ->orWhereIn('id', $this->referencedOperations()->select('configuration_operations.id')));
    }
}
