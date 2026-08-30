<?php

namespace App\Models;

use App\Models\Enums\Server\ServerTypeEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

class Server extends Model
{
    use HasFactory;

    private ?string $provisioningRootPassword = null;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_WAITING_FOR_IP = 'waiting_for_ip';

    public const STATUS_PROVISIONING = 'provisioning';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FAILED = 'failed';

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
        'provisioning_token',
    ];

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
        'provisioning_process_id' => 'integer',
        'type' => ServerTypeEnum::class,
        'password' => 'encrypted',
        'mysql_root_password' => 'encrypted',
        'ssh_private_key' => 'encrypted',
        'provisioned_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Server $server): void {
            $server->provisioning_token ??= (string) Str::uuid();
        });
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function events(): MorphMany
    {
        return $this->morphMany(Event::class, 'parentable');
    }

    public function logs(): MorphMany
    {
        return $this->morphMany(Log::class, 'parentable');
    }

    public function websites(): HasMany
    {
        return $this->hasMany(Website::class);
    }

    public function logSnapshots(): HasMany
    {
        return $this->hasMany(ServerLogSnapshot::class);
    }

    public function repositories(): HasMany
    {
        return $this->hasMany(Repository::class);
    }

    public function recipes(): BelongsToMany
    {
        return $this->belongsToMany(Recipe::class)
            ->withPivot('position')
            ->orderByPivot('position');
    }

    public function scopeReadyForWebsites(Builder $query): Builder
    {
        return $query
            ->where('provisioning_status', self::STATUS_ACTIVE)
            ->whereIn('type', ServerTypeEnum::websiteHostingValues())
            ->whereNotNull('mysql_root_password');
    }

    public function provisioningRootPassword(): ?string
    {
        return $this->provisioningRootPassword;
    }

    public function setProvisioningRootPassword(string $password): void
    {
        $this->provisioningRootPassword = $password;
    }
}
