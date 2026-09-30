<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ServerType;
use Carbon\CarbonImmutable;
use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A server the account runs sites and workers on: created at a cloud provider or imported over SSH.
 * Private keys, host keys and passwords are encrypted.
 *
 * @property int $id
 * @property string $account_id
 * @property string|null $created_by
 * @property int|null $provider_id
 * @property ServerType $type
 * @property string $name
 * @property string|null $display_name
 * @property string|null $identifier the provider's ID for the machine
 * @property string|null $region
 * @property string|null $size
 * @property float|null $monthly_cost
 * @property string|null $monthly_cost_currency USD or EUR
 * @property string|null $monthly_cost_source provider (from its size catalog) or manual (entered for an imported server)
 * @property CarbonImmutable|null $monthly_cost_checked_at
 * @property string|null $image
 * @property string|null $public_ip
 * @property string|null $private_ip
 * @property bool $trust_private_network whether the firewall lets the account's other servers in over the private network
 * @property int $ssh_port
 * @property string|null $ssh_fingerprint the provider's ID for the SSH key
 * @property bool $ssh_key_owned whether we created that key and should delete it
 * @property string|null $ssh_public_key
 * @property string|null $ssh_private_key
 * @property string|null $ssh_host_key
 * @property string|null $ssh_host_fingerprint
 * @property string|null $password only while a remote provisioning retry needs it
 * @property string|null $mysql_root_password the database admin password: MySQL's root, or PostgreSQL's postgres user
 * @property string|null $database_engine mysql or postgres, for database servers
 * @property string|null $node_version the Node.js major version chosen for the server; null for the latest installed at setup
 * @property int $setup_stage
 * @property string $provisioning_status queued, waiting_for_ip, provisioning, active or failed
 * @property string|null $provisioning_error
 * @property string|null $provisioning_failure_phase creation, initialization or remote
 * @property string|null $provisioning_token
 * @property string|null $initialization_token
 * @property int|null $provisioning_process_id
 * @property string|null $provisioning_process_path
 * @property CarbonImmutable|null $provisioned_at
 * @property list<array{name: string, description: string|null, script: string}>|null $recipe_snapshot
 * @property bool $snapshot_before_changes take a provider snapshot before updates, security fixes and runtime switches
 * @property int|null $patch_day the day of the week updates are installed (0 Sunday to 6 Saturday, UTC); null for no window
 * @property int $patch_hour the hour (UTC) the update window starts
 * @property bool $patch_reboot whether the window reboots the server when updates need it
 * @property Carbon|null $last_patched_at
 * @property string|null $last_patch_error
 * @property int|null $legacy_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Account $account
 * @property-read User|null $creator
 * @property-read Provider|null $provider
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ServerLogSnapshot> $logSnapshots
 * @property-read ServerDiagnosticSnapshot|null $diagnosticSnapshot
 */
#[Hidden(['password', 'mysql_root_password', 'ssh_private_key', 'ssh_host_key', 'provisioning_token', 'initialization_token', 'recipe_snapshot'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
#[UseFactory(ServerFactory::class)]
class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
    use HasFactory;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_WAITING_FOR_IP = 'waiting_for_ip';

    public const STATUS_PROVISIONING = 'provisioning';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FAILED = 'failed';

    public const PROVISIONING_STATUSES = [self::STATUS_QUEUED, self::STATUS_WAITING_FOR_IP, self::STATUS_PROVISIONING];

    public const FAILURE_CREATION = 'creation';

    public const FAILURE_INITIALIZATION = 'initialization';

    public const FAILURE_REMOTE = 'remote';

    /**
     * The root password for this provisioning run; shown once, never stored except while a retry needs it.
     *
     * @var string|null
     */
    private ?string $provisioningRootPassword = null;

    /**
     * Give each new server provisioning and initialisation tokens, so a job from an earlier attempt can tell it's
     * stale.
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::creating(function (Server $server): void {
            $server->provisioning_token ??= (string) Str::uuid();
            $server->initialization_token ??= (string) Str::uuid();
        });
    }

    /**
     * Get the account the server belongs to.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Get the person who created or imported the server (`created_by`).
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the cloud provider it runs on, including disconnected ones.
     *
     * @return BelongsTo<Provider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class)->withTrashed();
    }

    /**
     * Get the server's latest copy of each log.
     *
     * @return HasMany<ServerLogSnapshot, $this>
     */
    public function logSnapshots(): HasMany
    {
        return $this->hasMany(ServerLogSnapshot::class);
    }

    /**
     * Get the websites on the server.
     *
     * @return HasMany<Website, $this>
     */
    public function websites(): HasMany
    {
        return $this->hasMany(Website::class);
    }

    /**
     * Get the troubleshooting terminals opened on the server.
     *
     * @return HasMany<ServerTerminalSession, $this>
     */
    public function terminalSessions(): HasMany
    {
        return $this->hasMany(ServerTerminalSession::class);
    }

    /**
     * Get the commands run on the server from its page.
     *
     * @return HasMany<ServerCommandExecution, $this>
     */
    public function commandExecutions(): HasMany
    {
        return $this->hasMany(ServerCommandExecution::class);
    }

    /**
     * Get the server's resource samples.
     *
     * @return HasMany<ServerMetric, $this>
     */
    public function metrics(): HasMany
    {
        return $this->hasMany(ServerMetric::class);
    }

    /**
     * Get the server's latest diagnostic run.
     *
     * @return HasOne<ServerDiagnosticSnapshot, $this>
     */
    public function diagnosticSnapshot(): HasOne
    {
        return $this->hasOne(ServerDiagnosticSnapshot::class);
    }

    /**
     * Get the name to show: the display name when set, else the provider's name.
     *
     * @return string
     */
    public function label(): string
    {
        return $this->display_name ?? $this->name;
    }

    /**
     * Determine whether the server is still being set up.
     *
     * @return bool
     */
    public function isProvisioning(): bool
    {
        return in_array($this->provisioning_status, self::PROVISIONING_STATUSES, true);
    }

    /**
     * Get the recipes chosen when the server was created, which provisioning installs.
     *
     * @return list<array{name: string, description: string|null, script: string}>
     */
    public function provisioningRecipes(): array
    {
        return $this->recipe_snapshot ?? [];
    }

    /**
     * Get the root password handed to the provisioning script in this request only; it's never stored in this form.
     *
     * @return string|null
     */
    public function provisioningRootPassword(): ?string
    {
        return $this->provisioningRootPassword;
    }

    /**
     * Hand the root password to the provisioning script for this request only.
     *
     * @param  string  $password
     * @return void
     */
    public function setProvisioningRootPassword(string $password): void
    {
        $this->provisioningRootPassword = $password;
    }

    /**
     * Get the attributes that should be cast.
     *
     * Encrypts the root and MySQL passwords, SSH keys, pinned host key and recipe snapshot, and reads `type` as a
     * ServerType.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ServerType::class,
            'ssh_port' => 'integer',
            'trust_private_network' => 'boolean', 'snapshot_before_changes' => 'boolean',
            'patch_day' => 'integer',
            'patch_hour' => 'integer',
            'patch_reboot' => 'boolean',
            'last_patched_at' => 'datetime',
            'setup_stage' => 'integer',
            'provisioning_process_id' => 'integer',
            'password' => 'encrypted',
            'mysql_root_password' => 'encrypted',
            'ssh_private_key' => 'encrypted',
            'ssh_host_key' => 'encrypted',
            'ssh_key_owned' => 'boolean',
            'recipe_snapshot' => 'encrypted:array',
            'provisioned_at' => 'immutable_datetime',
            'monthly_cost' => 'float',
            'monthly_cost_checked_at' => 'immutable_datetime',
        ];
    }
}
