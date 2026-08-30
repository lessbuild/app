<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Repository extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function builds(): HasMany
    {
        return $this->hasMany(Build::class);
    }

    public function latestBuild(): HasOne
    {
        return $this->hasOne(Build::class)->latestOfMany();
    }

    public function isDeploymentReady(): bool
    {
        $this->loadMissing(['provider', 'website.server']);

        return $this->provider?->provider === Provider::TYPE_GITHUB
            && $this->website?->provisioning_status === Website::STATUS_ACTIVE
            && $this->website?->server?->provisioning_status === Server::STATUS_ACTIVE;
    }
}
