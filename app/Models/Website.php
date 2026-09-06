<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Scopes\WebsiteScopes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class Website extends Model
{
    use BelongsToOrganization;
    use HasFactory;
    use SoftDeletes;
    use WebsiteScopes;

    protected $attributes = [
        'health_monitoring_enabled' => true,
    ];

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROVISIONING = 'provisioning';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FAILED = 'failed';

    public const ACTIVE_PROVISIONING_STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_PROVISIONING,
    ];

    public const HEALTH_UNKNOWN = 'unknown';

    public const HEALTH_HEALTHY = 'healthy';

    public const HEALTH_UNHEALTHY = 'unhealthy';

    public const DEFAULT_HEALTH_CHECK_INTERVAL_MINUTES = 5;

    public const HEALTH_CHECK_INTERVALS = [5, 10, 15, 30, 60];

    public const DEFAULT_HEALTH_FAILURE_THRESHOLD = 3;

    public const HEALTH_FAILURE_THRESHOLDS = [1, 2, 3, 5, 10];

    public const PROVISIONING_LOG_TYPE = 'provisioning';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'websites';

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = [];

    protected $hidden = ['database_password', 'environment', 'provisioning_token'];

    protected $casts = [
        'database_password' => 'encrypted',
        'environment' => 'encrypted',
        'health_check_enabled' => 'boolean',
        'health_monitoring_enabled' => 'boolean',
        'health_check_interval_minutes' => 'integer',
        'health_failure_threshold' => 'integer',
        'health_failure_count' => 'integer',
        'health_last_checked_at' => 'datetime',
        'previous_server_id' => 'integer',
        'provisioned_at' => 'datetime',
        'release_retention' => 'integer',
        'log_retention_lines' => 'integer',
        'setup_stage' => 'integer',
    ];

    /** Maintain provisioning identity, unique deployment slugs, and primary domain records. */
    protected static function booted(): void
    {
        static::creating(function (Website $website): void {
            $website->provisioning_token ??= (string) Str::uuid();

            if ($website->deployment_slug) {
                return;
            }

            $base = Str::slug($website->getRawOriginal('name') ?: $website->name);
            $base = substr($base ?: 'website', 0, 32);
            $slug = $base;
            $suffix = 2;

            while (static::withTrashed()
                ->where('user_id', $website->user_id)
                ->where('deployment_slug', $slug)
                ->exists()) {
                $ending = '-'.$suffix++;
                $slug = substr($base, 0, 32 - strlen($ending)).$ending;
            }

            $website->deployment_slug = $slug;
        });

        static::created(function (Website $website): void {
            if (Schema::hasTable('website_domains') && ! WebsiteDomain::query()->where('hostname', $website->url)->exists()) {
                $website->domains()->firstOrCreate(['hostname' => $website->url], [
                    'created_by' => $website->user_id,
                    'type' => 'primary',
                    'dns_status' => 'active',
                ]);
            }
        });

        static::updated(function (Website $website): void {
            if ($website->wasChanged('url') && Schema::hasTable('website_domains')) {
                $website->domains()->where('type', 'primary')->update([
                    'hostname' => $website->url,
                    'dns_status' => 'pending',
                    'ssl_status' => 'pending',
                    'certificate_expires_at' => null,
                    'last_checked_at' => null,
                ]);
            }
        });
    }

    /**
     * Derive the database-safe name from the persistent deployment slug.
     *
     * @return string The deployment slug with hyphens replaced by underscores.
     */
    public function databaseIdentifier(): string
    {
        return str_replace('-', '_', $this->deployment_slug);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<Server, $this> */
    public function previousServer(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'previous_server_id');
    }

    /** @return HasMany<Repository, $this> */
    public function repositories(): HasMany
    {
        return $this->hasMany(Repository::class);
    }

    /** @return HasMany<Environment, $this> */
    public function environments(): HasMany
    {
        return $this->hasMany(Environment::class);
    }

    /** @return HasManyThrough<Build, Repository, $this> */
    public function builds(): HasManyThrough
    {
        return $this->hasManyThrough(Build::class, Repository::class);
    }

    /**
     * Check whether any repository still reserves this website for deployment.
     *
     * @return bool Whether an active build exists.
     */
    public function hasActiveDeployment(): bool
    {
        return $this->builds()->whereIn('builds.status', Build::ACTIVE_STATUSES)->exists();
    }

    /** @return MorphMany<Log, $this> */
    public function logs(): MorphMany
    {
        return $this->morphMany(Log::class, 'parentable');
    }

    /** @return MorphMany<Event, $this> */
    public function events(): MorphMany
    {
        return $this->morphMany(Event::class, 'parentable');
    }

    /** @return HasMany<WebsiteHealthCheck, $this> */
    public function healthChecks(): HasMany
    {
        return $this->hasMany(WebsiteHealthCheck::class);
    }

    /** @return HasMany<WebsiteLogSnapshot, $this> */
    public function runtimeLogs(): HasMany
    {
        return $this->hasMany(WebsiteLogSnapshot::class);
    }

    /** @return HasMany<WebsiteDomain, $this> */
    public function domains(): HasMany
    {
        return $this->hasMany(WebsiteDomain::class);
    }

    /** @return HasMany<WebsiteBackupSchedule, $this> */
    public function backupSchedules(): HasMany
    {
        return $this->hasMany(WebsiteBackupSchedule::class);
    }

    /** @return HasMany<WebsiteBackup, $this> */
    public function backups(): HasMany
    {
        return $this->hasMany(WebsiteBackup::class);
    }

    /**
     * Resolve the configured threshold against supported monitoring values.
     *
     * @return int The configured threshold, or the application default when unsupported.
     */
    public static function defaultHealthFailureThreshold(): int
    {
        $configured = (int) config('lessbuild.health_monitor_failure_threshold');

        return in_array($configured, self::HEALTH_FAILURE_THRESHOLDS, true)
            ? $configured
            : self::DEFAULT_HEALTH_FAILURE_THRESHOLD;
    }
}
