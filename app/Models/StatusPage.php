<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StatusPage extends Model
{
    protected $guarded = [];

    protected $casts = ['is_published' => 'boolean'];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsToMany<Website, $this> */
    public function websites(): BelongsToMany
    {
        return $this->belongsToMany(Website::class)
            ->withPivot(['display_name', 'position'])
            ->orderByPivot('position');
    }

    /** @return HasMany<StatusIncident, $this> */
    public function incidents(): HasMany
    {
        return $this->hasMany(StatusIncident::class);
    }

    /** @return HasMany<StatusSubscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(StatusSubscription::class);
    }
}
