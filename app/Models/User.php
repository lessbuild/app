<?php

namespace App\Models;

use App\Services\PersonalOrganization;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Cashier\Billable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmailContract
{
    use Billable, HasApiTokens, HasFactory, MustVerifyEmail, Notifiable;

    protected static function booted(): void
    {
        static::created(fn (User $user) => app(PersonalOrganization::class)->ensure($user));
    }

    public function billingPlan(): string
    {
        foreach (array_reverse(config('billing.plans')) as $plan => $details) {
            $prices = array_filter(array_unique([
                $details['price_id'] ?? null,
                $details['monthly_price_id'] ?? null,
                $details['yearly_price_id'] ?? null,
            ]));

            if (collect($prices)->contains(fn (string $price): bool => $this->subscribedToPrice($price))) {
                return $plan;
            }
        }

        return 'free';
    }

    public function isPlatformAdmin(): bool
    {
        return in_array(strtolower($this->email), config('lessbuild.platform_admin_emails', []), true);
    }

    public function billingInterval(): string
    {
        $plan = config('billing.plans.'.$this->billingPlan(), []);
        $subscription = $this->subscription('default');

        return $subscription && filled($plan['yearly_price_id'] ?? null) && $subscription->hasPrice($plan['yearly_price_id'])
            ? 'yearly'
            : 'monthly';
    }

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
        'current_organization_id',
        'preferences',
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
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password_set_at' => 'datetime',
        'two_factor_secret' => 'encrypted',
        'two_factor_recovery_codes' => 'array',
        'two_factor_confirmed_at' => 'datetime',
        'preferences' => 'array',
    ];

    public function hasLocalPassword(): bool
    {
        return $this->password_set_at !== null;
    }

    public function twoFactorEnabled(): bool
    {
        return filled($this->two_factor_secret) && $this->two_factor_confirmed_at !== null;
    }

    public function currentOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'current_organization_id');
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)->withPivot('role')->withTimestamps();
    }

    public function workspaceProviders(): HasMany
    {
        return $this->currentOrganization()->firstOrFail()->providers();
    }

    public function workspaceServers(): HasMany
    {
        return $this->currentOrganization()->firstOrFail()->servers();
    }

    public function workspaceWebsites(): HasMany
    {
        return $this->currentOrganization()->firstOrFail()->websites();
    }

    public function workspaceRepositories(): HasMany
    {
        return $this->currentOrganization()->firstOrFail()->repositories();
    }

    public function workspaceRecipes(): HasMany
    {
        return $this->currentOrganization()->firstOrFail()->recipes();
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

    public function webhookDeliveries(): HasManyThrough
    {
        return $this->hasManyThrough(RepositoryWebhookDelivery::class, Repository::class);
    }

    public function recipes(): HasMany
    {
        return $this->hasMany(Recipe::class);
    }

    public function recipeRatings(): HasMany
    {
        return $this->hasMany(RecipeRating::class);
    }

    public function recipeFavorites(): HasMany
    {
        return $this->hasMany(RecipeFavorite::class);
    }

    public function recipeReports(): HasMany
    {
        return $this->hasMany(RecipeReport::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function accountEvents(): MorphMany
    {
        return $this->morphMany(Event::class, 'parentable');
    }

    public function signIns(): HasMany
    {
        return $this->hasMany(SignInEvent::class);
    }

    public function commandExecutions(): HasMany
    {
        return $this->hasMany(ServerCommandExecution::class);
    }
}
