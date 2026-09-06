<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Enums\Server\ServerTypeEnum;
use App\Models\Scopes\ServerScopes;
use App\Presenters\ServerPresenter;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class Server extends Model
{
    use BelongsToOrganization, HasFactory;
    use ServerScopes;

    private ?string $provisioningRootPassword = null;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_WAITING_FOR_IP = 'waiting_for_ip';

    public const STATUS_PROVISIONING = 'provisioning';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FAILED = 'failed';

    public const ACTIVE_PROVISIONING_STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_WAITING_FOR_IP,
        self::STATUS_PROVISIONING,
    ];

    public const FAILURE_CREATION = 'creation';

    public const FAILURE_INITIALIZATION = 'initialization';

    public const FAILURE_REMOTE = 'remote';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'servers';

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'mysql_root_password',
        'ssh_private_key',
        'ssh_host_key',
        'provisioning_token',
        'initialization_token',
        'recipe_snapshot',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = [];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'setup_stage' => 'integer',
        'ssh_port' => 'integer',
        'provisioning_process_id' => 'integer',
        'type' => ServerTypeEnum::class,
        'password' => 'encrypted',
        'mysql_root_password' => 'encrypted',
        'ssh_private_key' => 'encrypted',
        'ssh_host_key' => 'encrypted',
        'ssh_key_owned' => 'boolean',
        'recipe_snapshot' => 'encrypted:array',
        'provisioned_at' => 'datetime',
    ];

    /** Initialize stable provisioning identities for newly created servers. */
    protected static function booted(): void
    {
        static::creating(function (Server $server): void {
            $server->provisioning_token ??= (string) Str::uuid();
            $server->initialization_token ??= (string) Str::uuid();
        });
    }

    /** @return Attribute<string|null, never> The public display label for this server. */
    protected function label(): Attribute
    {
        return Attribute::get(fn (): ?string => ServerPresenter::label($this));
    }

    /** @return BelongsTo<Provider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    /** @return MorphMany<Event, $this> */
    public function events(): MorphMany
    {
        return $this->morphMany(Event::class, 'parentable');
    }

    /** @return MorphMany<Log, $this> */
    public function logs(): MorphMany
    {
        return $this->morphMany(Log::class, 'parentable');
    }

    /** @return HasMany<Website, $this> */
    public function websites(): HasMany
    {
        return $this->hasMany(Website::class);
    }

    /** @return HasMany<ServerLogSnapshot, $this> */
    public function logSnapshots(): HasMany
    {
        return $this->hasMany(ServerLogSnapshot::class);
    }

    /** @return HasMany<ServerMetric, $this> */
    public function metrics(): HasMany
    {
        return $this->hasMany(ServerMetric::class);
    }

    /** @return HasMany<ServerCommandExecution, $this> */
    public function commandExecutions(): HasMany
    {
        return $this->hasMany(ServerCommandExecution::class);
    }

    /** @return HasMany<Repository, $this> */
    public function repositories(): HasMany
    {
        return $this->hasMany(Repository::class);
    }

    /** @return BelongsToMany<Recipe, $this> */
    public function recipes(): BelongsToMany
    {
        return $this->belongsToMany(Recipe::class)
            ->withPivot('position')
            ->orderByPivot('position');
    }

    /**
     * @return Collection<int, array{name: string, description: ?string, script: string}>
     */
    public function provisioningRecipes(): Collection
    {
        if ($this->recipe_snapshot !== null) {
            return collect($this->recipe_snapshot);
        }

        return $this->recipes()->get()->map(fn (Recipe $recipe): array => [
            'name' => $recipe->name,
            'description' => $recipe->description,
            'script' => $recipe->script,
        ]);
    }

    /** Persist an encrypted, ordered recipe snapshot for provisioning retries. */
    public function captureProvisioningRecipes(): void
    {
        $this->forceFill([
            'recipe_snapshot' => $this->recipes()->get()->map(fn (Recipe $recipe): array => [
                'name' => $recipe->name,
                'description' => $recipe->description,
                'script' => $recipe->script,
            ])->values()->all(),
        ])->save();
    }

    /**
     * Read the temporary root credential kept only on this model instance.
     *
     * @return ?string The credential, or null when it has not been supplied.
     */
    public function provisioningRootPassword(): ?string
    {
        return $this->provisioningRootPassword;
    }

    /**
     * Keep the initial root credential in memory for provisioning.
     *
     * @param  string  $password  The supplied root credential; this method does not persist it.
     */
    public function setProvisioningRootPassword(string $password): void
    {
        $this->provisioningRootPassword = $password;
    }
}
