<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Provider extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const TYPE_DIGITALOCEAN = 'digitalocean';

    public const TYPE_GITHUB = 'github';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'description',
        'provider',
        'token',
    ];

    protected $hidden = ['token'];

    protected $casts = ['token' => 'encrypted'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    public function repositories(): HasMany
    {
        return $this->hasMany(Repository::class);
    }

    public function scopeForServers(Builder $query): Builder
    {
        return $query->where('provider', self::TYPE_DIGITALOCEAN);
    }

    public function scopeForRepositories(Builder $query): Builder
    {
        return $query->where('provider', self::TYPE_GITHUB);
    }

    public function hasAttachedResources(): bool
    {
        return $this->servers()->exists() || $this->repositories()->exists();
    }
}
