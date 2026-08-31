<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Provider extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const TYPE_DIGITALOCEAN = 'digitalocean';

    public const TYPE_GITHUB = 'github';

    public const TYPE_GITLAB = 'gitlab';

    public const TYPE_BITBUCKET = 'bitbucket';

    public const CONNECTION_UNCHECKED = 'unchecked';

    public const CONNECTION_HEALTHY = 'healthy';

    public const CONNECTION_FAILED = 'failed';

    public const CONNECTION_STATUSES = [
        self::CONNECTION_HEALTHY,
        self::CONNECTION_FAILED,
        self::CONNECTION_UNCHECKED,
    ];

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
        'connection_status',
        'connection_checked_at',
    ];

    protected $hidden = ['token'];

    protected $casts = [
        'token' => 'encrypted',
        'connection_checked_at' => 'datetime',
    ];

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

    public function events(): MorphMany
    {
        return $this->morphMany(Event::class, 'parentable');
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

    public function connectionHealth(): string
    {
        return $this->connection_status ?? self::CONNECTION_UNCHECKED;
    }

    public function recordConnectionResult(
        bool $successful,
        string $providerType,
        string $encryptedToken,
        ?string $previousCheckedAt,
    ): bool {
        $checkedAt = now();
        if ($previousCheckedAt !== null && $checkedAt->lessThanOrEqualTo($previousCheckedAt)) {
            $checkedAt = Carbon::parse($previousCheckedAt)->addSecond();
        }

        $attributes = [
            'connection_status' => $successful ? self::CONNECTION_HEALTHY : self::CONNECTION_FAILED,
            'connection_checked_at' => $checkedAt,
        ];

        $recorded = static::withoutTimestamps(function () use (
            $providerType,
            $encryptedToken,
            $previousCheckedAt,
            $attributes,
        ): bool {
            $query = static::query()
                ->whereKey($this->getKey())
                ->where('provider', $providerType)
                ->where('token', $encryptedToken);

            $previousCheckedAt === null
                ? $query->whereNull('connection_checked_at')
                : $query->where('connection_checked_at', $previousCheckedAt);

            return $query->update($attributes) === 1;
        });

        if ($recorded) {
            $this->forceFill($attributes);
        }

        return $recorded;
    }

    public function resetConnectionHealth(): void
    {
        static::withoutTimestamps(function (): void {
            $this->forceFill([
                'connection_status' => null,
                'connection_checked_at' => null,
            ])->save();
        });
    }
}
