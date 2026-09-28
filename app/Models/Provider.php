<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProviderType;
use Carbon\CarbonImmutable;
use Database\Factories\ProviderFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * An account's credential for a cloud, DNS or Git host. The token is encrypted and never shown again.
 *
 * @property int $id
 * @property string $account_id
 * @property string|null $created_by
 * @property string $name
 * @property string|null $description
 * @property ProviderType $type
 * @property string $credential_type
 * @property string|null $external_id
 * @property string $token
 * @property string $connection_status unchecked, healthy or failed
 * @property CarbonImmutable|null $connection_checked_at
 * @property bool $connection_monitoring_enabled
 * @property int $connection_check_interval_minutes
 * @property int $connection_failure_threshold
 * @property int $connection_failure_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Account $account
 * @property-read User|null $creator
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Server> $servers
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ProviderConnectionCheck> $connectionChecks
 */
#[Hidden(['token'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
#[UseFactory(ProviderFactory::class)]
class Provider extends Model
{
    /** @use HasFactory<ProviderFactory> */
    use HasFactory, SoftDeletes;

    public const CHECK_INTERVALS = [60, 360, 720, 1440];

    public const FAILURE_THRESHOLDS = [1, 2, 3, 5];

    /**
     * Get the account the provider is connected to.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Get the person who connected the provider (`created_by`), who is told when the connection breaks.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the servers created or imported through the provider.
     *
     * @return HasMany<Server, $this>
     */
    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    /**
     * Get the provider's credential checks.
     *
     * @return HasMany<ProviderConnectionCheck, $this>
     */
    public function connectionChecks(): HasMany
    {
        return $this->hasMany(ProviderConnectionCheck::class);
    }

    /**
     * Determine whether anything still depends on the provider (servers or repositories), which blocks disconnecting
     * it.
     *
     * @return bool
     */
    public function hasAttachedResources(): bool
    {
        return $this->servers()->exists() || $this->repositories()->exists();
    }

    /**
     * Get the repositories cloned through the provider.
     *
     * @return HasMany<Repository, $this>
     */
    public function repositories(): HasMany
    {
        return $this->hasMany(Repository::class);
    }

    /**
     * Determine whether this is a GitHub App installation (credential_type `app`, external_id the installation ID)
     * rather than a token.
     *
     * @return bool
     */
    public function isGitHubApp(): bool
    {
        return $this->type === ProviderType::GitHub && $this->credential_type === 'app' && filled($this->external_id);
    }

    /**
     * Determine whether a repository URL (`host/owner/name`) is on this provider's Git host.
     *
     * @param  string  $url
     * @return bool
     */
    public function supportsRepositoryUrl(string $url): bool
    {
        $host = $this->type->repositoryHost();

        return $host !== null && str_starts_with(strtolower($url), $host.'/');
    }

    /**
     * Get the attributes that should be cast.
     *
     * Encrypts `token` and reads `type` as a ProviderType.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ProviderType::class,
            'token' => 'encrypted',
            'connection_checked_at' => 'immutable_datetime',
            'connection_monitoring_enabled' => 'boolean',
            'connection_check_interval_minutes' => 'integer',
            'connection_failure_threshold' => 'integer',
            'connection_failure_count' => 'integer',
        ];
    }
}
