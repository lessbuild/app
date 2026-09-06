<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
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

    public function databaseIdentifier(): string
    {
        return str_replace('-', '_', $this->deployment_slug);
    }

    public function user(): BelongsTo
    {
        return $this->BelongsTo(User::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function previousServer(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'previous_server_id');
    }

    public function repositories(): HasMany
    {
        return $this->hasMany(Repository::class);
    }

    public function environments(): HasMany
    {
        return $this->hasMany(Environment::class);
    }

    public function builds(): HasManyThrough
    {
        return $this->hasManyThrough(Build::class, Repository::class);
    }

    public function hasActiveDeployment(): bool
    {
        return $this->builds()->whereIn('builds.status', Build::ACTIVE_STATUSES)->exists();
    }

    public function logs(): MorphMany
    {
        return $this->morphMany(Log::class, 'parentable');
    }

    public function events(): MorphMany
    {
        return $this->morphMany(Event::class, 'parentable');
    }

    public function healthChecks(): HasMany
    {
        return $this->hasMany(WebsiteHealthCheck::class);
    }

    public function runtimeLogs(): HasMany
    {
        return $this->hasMany(WebsiteLogSnapshot::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(WebsiteDomain::class);
    }

    public function backupSchedules(): HasMany
    {
        return $this->hasMany(WebsiteBackupSchedule::class);
    }

    public function backups(): HasMany
    {
        return $this->hasMany(WebsiteBackup::class);
    }

    public static function defaultHealthFailureThreshold(): int
    {
        $configured = (int) config('lessbuild.health_monitor_failure_threshold');

        return in_array($configured, self::HEALTH_FAILURE_THRESHOLDS, true)
            ? $configured
            : self::DEFAULT_HEALTH_FAILURE_THRESHOLD;
    }

    public function scopeReadyForDeployments(Builder $query): Builder
    {
        return $query
            ->where('provisioning_status', self::STATUS_ACTIVE)
            ->whereHas('server', fn (Builder $query) => $query
                ->where('provisioning_status', Server::STATUS_ACTIVE));
    }

    public function scopeNeedsAttention(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                ->where('provisioning_status', self::STATUS_FAILED)
                ->orWhere(function (Builder $query): void {
                    $query
                        ->where('health_check_enabled', true)
                        ->where('health_status', self::HEALTH_UNHEALTHY);
                });
        });
    }
}
