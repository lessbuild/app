<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\WebsiteFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A site on an app server: a Caddy site, a MySQL database and user, and a .env file under /var/www/{slug}. It belongs to
 * the account (like servers); Deploy links it to an environment. The .env and database password are encrypted.
 *
 * @property int $id
 * @property string $account_id
 * @property string|null $created_by
 * @property int|null $server_id
 * @property int|null $previous_server_id set while the old server still has a copy to clean up after a move
 * @property string|null $environment_id
 * @property string $name
 * @property string|null $description
 * @property string $url the primary hostname
 * @property string $deployment_slug directory, Caddy file and database name on the server
 * @property string|null $env_file
 * @property string|null $database_password
 * @property int $setup_stage
 * @property string $provisioning_status queued, provisioning, active or failed
 * @property string|null $provisioning_error
 * @property string|null $placement_cleanup_error
 * @property string|null $provisioning_token
 * @property CarbonImmutable|null $provisioned_at
 * @property int $release_retention
 * @property int $log_retention_lines
 * @property bool $health_check_enabled
 * @property string $health_check_path
 * @property bool $health_monitoring_enabled
 * @property int $health_check_interval_minutes
 * @property int $health_failure_threshold
 * @property int $health_failure_count
 * @property string $health_status
 * @property CarbonImmutable|null $health_last_checked_at
 * @property string|null $health_last_error
 * @property int|null $legacy_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Account $account
 * @property-read User|null $creator
 * @property-read Server|null $server
 * @property-read Server|null $previousServer
 * @property-read Environment|null $environment
 * @property-read \Illuminate\Database\Eloquent\Collection<int, WebsiteDomain> $domains
 * @property-read \Illuminate\Database\Eloquent\Collection<int, WebsiteLog> $logs
 */
#[Hidden(['env_file', 'database_password', 'provisioning_token'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
#[UseFactory(WebsiteFactory::class)]
class Website extends Model
{
    /** @use HasFactory<WebsiteFactory> */
    use HasFactory, SoftDeletes;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROVISIONING = 'provisioning';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FAILED = 'failed';

    public const HEALTH_CHECK_INTERVALS = [5, 10, 15, 30, 60];

    public const HEALTH_FAILURE_THRESHOLDS = [1, 2, 3, 5, 10];

    protected static function booted(): void
    {
        static::creating(function (Website $website): void {
            $website->provisioning_token ??= (string) Str::uuid();
            if (($website->deployment_slug ?? '') !== '') {
                return;
            }
            $base = substr(Str::slug($website->name) ?: 'website', 0, 32);
            $slug = $base;
            for ($suffix = 2; static::withTrashed()->where('account_id', $website->account_id)->where('deployment_slug', $slug)->exists(); $suffix++) {
                $slug = substr($base, 0, 32 - strlen("-{$suffix}"))."-{$suffix}";
            }
            $website->deployment_slug = $slug;
        });
        // The primary domain follows the website's URL.
        static::created(function (Website $website): void {
            if (! WebsiteDomain::query()->where('hostname', $website->url)->exists()) {
                $domain = new WebsiteDomain;
                $domain->forceFill(['website_id' => $website->id, 'hostname' => $website->url, 'created_by' => $website->created_by, 'type' => 'primary', 'dns_status' => 'active'])->save();
            }
        });
        static::updated(function (Website $website): void {
            if ($website->wasChanged('url')) {
                $website->domains()->where('type', 'primary')->update(['hostname' => $website->url, 'dns_status' => 'pending', 'ssl_status' => 'pending', 'certificate_expires_at' => null, 'last_checked_at' => null]);
            }
        });
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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

    /** @return BelongsTo<Environment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /** @return HasMany<WebsiteDomain, $this> */
    public function domains(): HasMany
    {
        return $this->hasMany(WebsiteDomain::class);
    }

    /** @return HasMany<WebsiteLog, $this> */
    public function logs(): HasMany
    {
        return $this->hasMany(WebsiteLog::class);
    }

    public function databaseIdentifier(): string
    {
        return str_replace('-', '_', $this->deployment_slug);
    }

    /** Where a release phase lives on the server. Deploy adds the repository's subdirectory. */
    public function deploymentPath(string $phase): string
    {
        return "/var/www/{$this->deployment_slug}/{$phase}";
    }

    public function isProvisioning(): bool
    {
        return in_array($this->provisioning_status, [self::STATUS_QUEUED, self::STATUS_PROVISIONING], true);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'env_file' => 'encrypted', 'database_password' => 'encrypted', 'setup_stage' => 'integer', 'previous_server_id' => 'integer',
            'provisioned_at' => 'immutable_datetime', 'release_retention' => 'integer', 'log_retention_lines' => 'integer',
            'health_check_enabled' => 'boolean', 'health_monitoring_enabled' => 'boolean', 'health_check_interval_minutes' => 'integer',
            'health_failure_threshold' => 'integer', 'health_failure_count' => 'integer', 'health_last_checked_at' => 'immutable_datetime',
        ];
    }
}
