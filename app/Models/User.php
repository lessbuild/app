<?php

namespace App\Models;

use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmailContract
{
    use HasApiTokens, HasFactory, MustVerifyEmail, Notifiable;

    public const SOCIAL_PROVIDER_COLUMNS = [
        'github' => 'github_id',
        'gitlab' => 'gitlab_id',
        'bitbucket' => 'bitbucket_id',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'password_set_at',
        'github_id',
        'gitlab_id',
        'bitbucket_id',
        'auth_type',
        'email_verified_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'password_set_at',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password_set_at' => 'datetime',
    ];

    public function hasLocalPassword(): bool
    {
        return $this->password_set_at !== null;
    }

    /** @return list<string> */
    public function connectedSocialProviders(): array
    {
        return collect(self::SOCIAL_PROVIDER_COLUMNS)
            ->filter(fn (string $column): bool => filled($this->{$column}))
            ->keys()
            ->values()
            ->all();
    }

    public function websites(): HasMany
    {
        return $this->hasMany(Website::class);
    }

    public function providers(): HasMany
    {
        return $this->hasMany(Provider::class);
    }

    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    public function repositories(): HasMany
    {
        return $this->hasMany(Repository::class);
    }

    public function builds(): HasManyThrough
    {
        return $this->hasManyThrough(Build::class, Repository::class);
    }

    public function recipes(): HasMany
    {
        return $this->hasMany(Recipe::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function commandExecutions(): HasMany
    {
        return $this->hasMany(ServerCommandExecution::class);
    }
}
