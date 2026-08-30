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

    public const TYPE_GITLAB = 'gitlab';

    public const TYPE_BITBUCKET = 'bitbucket';

    public const SOURCE_CONTROL_TYPES = [
        self::TYPE_GITHUB,
        self::TYPE_GITLAB,
        self::TYPE_BITBUCKET,
    ];

    public const SERVER_TYPES = [
        self::TYPE_DIGITALOCEAN,
    ];

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
        return $query->whereIn('provider', self::SERVER_TYPES);
    }

    public function scopeForRepositories(Builder $query): Builder
    {
        return $query->whereIn('provider', self::SOURCE_CONTROL_TYPES);
    }

    public function isSourceControl(): bool
    {
        return in_array($this->provider, self::SOURCE_CONTROL_TYPES, true);
    }

    public function repositoryHost(): ?string
    {
        return match ($this->provider) {
            self::TYPE_GITHUB => 'github.com',
            self::TYPE_GITLAB => 'gitlab.com',
            self::TYPE_BITBUCKET => 'bitbucket.org',
            default => null,
        };
    }

    public function repositoryCredentialUsername(): ?string
    {
        return match ($this->provider) {
            self::TYPE_GITHUB => 'x-access-token',
            self::TYPE_GITLAB => 'oauth2',
            self::TYPE_BITBUCKET => 'x-token-auth',
            default => null,
        };
    }

    public function supportsRepositoryUrl(string $url): bool
    {
        $host = $this->repositoryHost();

        return $host !== null && str_starts_with(strtolower($url), $host.'/');
    }

    public function hasAttachedResources(): bool
    {
        return $this->servers()->exists() || $this->repositories()->exists();
    }
}
