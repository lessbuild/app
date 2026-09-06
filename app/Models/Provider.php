<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Scopes\ProviderScopes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Provider extends Model
{
    use BelongsToOrganization;
    use HasFactory;
    use ProviderScopes;
    use SoftDeletes;

    protected $attributes = [
        'connection_monitoring_enabled' => true,
        'connection_check_interval_minutes' => self::DEFAULT_CONNECTION_CHECK_INTERVAL_MINUTES,
        'connection_failure_threshold' => self::DEFAULT_CONNECTION_FAILURE_THRESHOLD,
        'connection_failure_count' => 0,
    ];

    public const DEFAULT_CONNECTION_CHECK_INTERVAL_MINUTES = 1440;

    public const CONNECTION_CHECK_INTERVALS = [60, 360, 720, 1440];

    public const DEFAULT_CONNECTION_FAILURE_THRESHOLD = 1;

    public const CONNECTION_FAILURE_THRESHOLDS = [1, 2, 3, 5];

    public const TYPE_DIGITALOCEAN = 'digitalocean';

    public const TYPE_HETZNER = 'hetzner';

    public const TYPE_VULTR = 'vultr';

    public const TYPE_GITHUB = 'github';

    public const TYPE_GITLAB = 'gitlab';

    public const TYPE_BITBUCKET = 'bitbucket';

    public const TYPE_CLOUDFLARE = 'cloudflare';

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
        self::TYPE_HETZNER,
        self::TYPE_VULTR,
    ];

    public const DNS_TYPES = [self::TYPE_CLOUDFLARE];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'description',
        'provider',
        'credential_type',
        'external_id',
        'token',
        'connection_status',
        'connection_checked_at',
        'connection_monitoring_enabled',
        'connection_check_interval_minutes',
        'connection_failure_threshold',
        'connection_failure_count',
    ];

    protected $hidden = ['token'];

    protected $casts = [
        'token' => 'encrypted',
        'connection_checked_at' => 'datetime',
        'connection_monitoring_enabled' => 'boolean',
        'connection_check_interval_minutes' => 'integer',
        'connection_failure_threshold' => 'integer',
        'connection_failure_count' => 'integer',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Server, $this> */
    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    /** @return HasMany<Repository, $this> */
    public function repositories(): HasMany
    {
        return $this->hasMany(Repository::class);
    }

    /** @return HasMany<WebsiteDomain, $this> */
    public function domains(): HasMany
    {
        return $this->hasMany(WebsiteDomain::class, 'dns_provider_id');
    }

    /** @return HasMany<ProviderConnectionCheck, $this> */
    public function connectionChecks(): HasMany
    {
        return $this->hasMany(ProviderConnectionCheck::class);
    }

    /** @return MorphMany<Event, $this> */
    public function events(): MorphMany
    {
        return $this->morphMany(Event::class, 'parentable');
    }

    /**
     * Determine whether this provider supplies source control repositories.
     *
     * @return bool Whether the provider type supports repository access.
     */
    public function isSourceControl(): bool
    {
        return in_array($this->provider, self::SOURCE_CONTROL_TYPES, true);
    }

    /**
     * Check that GitHub App authentication has a recorded installation identity.
     *
     * @return bool Whether the provider represents a configured GitHub App installation.
     */
    public function isGitHubApp(): bool
    {
        return $this->provider === self::TYPE_GITHUB && $this->credential_type === 'app' && filled($this->external_id);
    }

    /**
     * Resolve the supported source control host for this provider type.
     *
     * @return ?string The canonical host, or null for non-source-control providers.
     */
    public function repositoryHost(): ?string
    {
        return match ($this->provider) {
            self::TYPE_GITHUB => 'github.com',
            self::TYPE_GITLAB => 'gitlab.com',
            self::TYPE_BITBUCKET => 'bitbucket.org',
            default => null,
        };
    }

    /**
     * Resolve the username required for token-based Git HTTPS authentication.
     *
     * @return ?string The token username, or null for non-source-control providers.
     */
    public function repositoryCredentialUsername(): ?string
    {
        return match ($this->provider) {
            self::TYPE_GITHUB => 'x-access-token',
            self::TYPE_GITLAB => 'oauth2',
            self::TYPE_BITBUCKET => 'x-token-auth',
            default => null,
        };
    }

    /**
     * Check the normalized repository path against this provider's canonical host.
     *
     * @param  string  $url  The repository path in host/owner/repository format.
     * @return bool Whether the path belongs to this source control provider.
     */
    public function supportsRepositoryUrl(string $url): bool
    {
        $host = $this->repositoryHost();

        return $host !== null && str_starts_with(strtolower($url), $host.'/');
    }

    /**
     * Check for attached infrastructure, source repositories, or DNS domains.
     *
     * @return bool Whether any supported resource is attached.
     */
    public function hasAttachedResources(): bool
    {
        return $this->servers()->exists() || $this->repositories()->exists() || $this->domains()->exists();
    }

    /**
     * Resolve the last known connection state for historical unchecked records.
     *
     * @return string The recorded state, or unchecked when no result exists.
     */
    public function connectionHealth(): string
    {
        return $this->connection_status ?? self::CONNECTION_UNCHECKED;
    }

    /**
     * Resolve the configured provider monitoring interval against supported values.
     *
     * @return int The configured interval in minutes, or the application default.
     */
    public static function defaultConnectionCheckInterval(): int
    {
        $configured = (int) config('lessbuild.provider_health_interval_minutes');

        return in_array($configured, self::CONNECTION_CHECK_INTERVALS, true)
            ? $configured
            : self::DEFAULT_CONNECTION_CHECK_INTERVAL_MINUTES;
    }

    /**
     * Resolve the configured failure threshold against supported monitoring values.
     *
     * @return int The configured threshold, or the application default.
     */
    public static function defaultConnectionFailureThreshold(): int
    {
        $configured = (int) config('lessbuild.provider_health_failure_threshold');

        return in_array($configured, self::CONNECTION_FAILURE_THRESHOLDS, true)
            ? $configured
            : self::DEFAULT_CONNECTION_FAILURE_THRESHOLD;
    }

    /**
     * Persist a connection result only while the checked credentials and health state are current.
     *
     * @param  bool  $successful  Whether the connection check succeeded.
     * @param  string  $providerType  The provider type observed before the check.
     * @param  string  $encryptedToken  The stored ciphertext observed before the check.
     * @param  ?string  $previousCheckedAt  The prior check timestamp, or null for an unchecked provider.
     * @param  int  $previousFailureCount  The failure count observed before the check.
     * @param  int  $failureThreshold  The configured failure threshold observed before the check.
     * @param  bool  $automatic  Whether monitoring must still be enabled when the result is saved.
     * @return bool Whether the unchanged provider accepted this result.
     */
    public function recordConnectionResult(
        bool $successful,
        string $providerType,
        string $encryptedToken,
        ?string $previousCheckedAt,
        int $previousFailureCount,
        int $failureThreshold,
        bool $automatic = false,
    ): bool {
        $checkedAt = now();
        if ($previousCheckedAt !== null && $checkedAt->lessThanOrEqualTo($previousCheckedAt)) {
            $checkedAt = Carbon::parse($previousCheckedAt)->addSecond();
        }

        $effectiveThreshold = in_array($failureThreshold, self::CONNECTION_FAILURE_THRESHOLDS, true)
            ? $failureThreshold
            : self::DEFAULT_CONNECTION_FAILURE_THRESHOLD;
        $failureCount = $successful ? 0 : min(65535, $previousFailureCount + 1);
        $attributes = [
            'connection_status' => match (true) {
                $successful => self::CONNECTION_HEALTHY,
                $failureCount >= $effectiveThreshold => self::CONNECTION_FAILED,
                default => $this->connection_status,
            },
            'connection_failure_count' => $failureCount,
            'connection_checked_at' => $checkedAt,
        ];

        $recorded = static::withoutTimestamps(function () use (
            $providerType,
            $encryptedToken,
            $previousCheckedAt,
            $attributes,
            $automatic,
            $previousFailureCount,
            $failureThreshold,
        ): bool {
            $query = static::query()
                ->whereKey($this->getKey())
                ->where('provider', $providerType)
                ->where('token', $encryptedToken)
                ->where('connection_failure_count', $previousFailureCount)
                ->where('connection_failure_threshold', $failureThreshold);

            if ($automatic) {
                $query->where('connection_monitoring_enabled', true);
            }

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

    /** Clear saved connection state after credentials change without updating modification time. */
    public function resetConnectionHealth(): void
    {
        static::withoutTimestamps(function (): void {
            $this->forceFill([
                'connection_status' => null,
                'connection_checked_at' => null,
                'connection_failure_count' => 0,
            ])->save();
        });
    }
}
