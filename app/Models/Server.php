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
 * @property int $ssh_port
 * @property string|null $ssh_fingerprint the provider's ID for the SSH key
 * @property bool $ssh_key_owned whether we created that key and should delete it
 * @property string|null $ssh_public_key
 * @property string|null $ssh_private_key
 * @property string|null $ssh_host_key
 * @property string|null $ssh_host_fingerprint
 * @property string|null $password only while a remote provisioning retry needs it
 * @property string|null $mysql_root_password
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
     * Gives each new server provisioning and initialisation tokens, so a job from an earlier attempt can tell it's
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
     * The account the server belongs to.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Who created or imported it (`created_by`).
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The cloud provider it runs on, including disconnected ones.
     *
     * @return BelongsTo<Provider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class)->withTrashed();
    }

    /**
     * Its latest copy of each log.
     *
     * @return HasMany<ServerLogSnapshot, $this>
     */
    public function logSnapshots(): HasMany
    {
        return $this->hasMany(ServerLogSnapshot::class);
    }

    /**
     * Websites on the server.
     *
     * @return HasMany<Website, $this>
     */
    public function websites(): HasMany
    {
        return $this->hasMany(Website::class);
    }

    /**
     * Troubleshooting terminals opened on it.
     *
     * @return HasMany<ServerTerminalSession, $this>
     */
    public function terminalSessions(): HasMany
    {
        return $this->hasMany(ServerTerminalSession::class);
    }

    /**
     * Commands run on it from the server page.
     *
     * @return HasMany<ServerCommandExecution, $this>
     */
    public function commandExecutions(): HasMany
    {
        return $this->hasMany(ServerCommandExecution::class);
    }

    /**
     * Its resource samples.
     *
     * @return HasMany<ServerMetric, $this>
     */
    public function metrics(): HasMany
    {
        return $this->hasMany(ServerMetric::class);
    }

    /**
     * Its latest diagnostic run.
     *
     * @return HasOne<ServerDiagnosticSnapshot, $this>
     */
    public function diagnosticSnapshot(): HasOne
    {
        return $this->hasOne(ServerDiagnosticSnapshot::class);
    }

    /**
     * The name to show: the display name when set, else the provider's name.
     *
     * @return string
     */
    public function label(): string
    {
        return $this->display_name ?? $this->name;
    }

    /**
     * Whether the server is still being set up.
     *
     * @return bool
     */
    public function isProvisioning(): bool
    {
        return in_array($this->provisioning_status, self::PROVISIONING_STATUSES, true);
    }

    /**
     * The recipes chosen when the server was created, which provisioning installs.
     *
     * @return list<array{name: string, description: string|null, script: string}>
     */
    public function provisioningRecipes(): array
    {
        return $this->recipe_snapshot ?? [];
    }

    /**
     * The root password handed to the provisioning script in this request only; it's never stored in this form.
     *
     * @return string|null
     */
    public function provisioningRootPassword(): ?string
    {
        return $this->provisioningRootPassword;
    }

    /**
     * Hands the root password to the provisioning script for this request only.
     *
     * @param  string  $password
     * @return void
     */
    public function setProvisioningRootPassword(string $password): void
    {
        $this->provisioningRootPassword = $password;
    }

    /**
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
