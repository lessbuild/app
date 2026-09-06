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

    /** Ensure each newly created account has a personal workspace. */
    protected static function booted(): void
    {
        static::created(fn (User $user): Organization => app(PersonalOrganization::class)->ensure($user));
    }

    /**
     * Resolve the highest configured plan covered by an active subscription price.
     *
     * @return string The subscribed plan key, or free when no paid plan matches.
     */
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

    /**
     * Check the normalized account email against configured platform administrators.
     *
     * @return bool Whether this account has platform administrator access.
     */
    public function isPlatformAdmin(): bool
    {
        return in_array(strtolower($this->email), config('lessbuild.platform_admin_emails', []), true);
    }

    /**
     * Resolve the billing cadence from the current plan and default subscription.
     *
     * @return string Yearly for a matching yearly price; otherwise monthly.
     */
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

    /**
     * Check whether the account has explicitly configured password authentication.
     *
     * @return bool Whether a local password setup timestamp exists.
     */
    public function hasLocalPassword(): bool
    {
        return $this->password_set_at !== null;
    }

    /**
     * Check that two-factor credentials have been configured and confirmed.
     *
     * @return bool Whether two-factor authentication is enabled.
     */
    public function twoFactorEnabled(): bool
    {
        return filled($this->two_factor_secret) && $this->two_factor_confirmed_at !== null;
    }

    /** @return BelongsTo<Organization, $this> */
    public function currentOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'current_organization_id');
    }

    /** @return BelongsToMany<Organization, $this> */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)->withPivot('role')->withTimestamps();
    }

    /** @return HasMany<Provider, Organization> */
    public function workspaceProviders(): HasMany
    {
        return $this->currentOrganization()->firstOrFail()->providers();
    }

    /** @return HasMany<Server, Organization> */
    public function workspaceServers(): HasMany
    {
        return $this->currentOrganization()->firstOrFail()->servers();
    }

    /** @return HasMany<Website, Organization> */
    public function workspaceWebsites(): HasMany
    {
        return $this->currentOrganization()->firstOrFail()->websites();
    }

    /** @return HasMany<Repository, Organization> */
    public function workspaceRepositories(): HasMany
    {
        return $this->currentOrganization()->firstOrFail()->repositories();
    }

    /** @return HasMany<Recipe, Organization> */
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

    /** @return HasMany<Website, $this> */
    public function websites(): HasMany
    {
        return $this->hasMany(Website::class);
    }

    /** @return HasMany<Provider, $this> */
    public function providers(): HasMany
    {
        return $this->hasMany(Provider::class);
    }

    /** @return HasMany<Server, $this> */
    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    /** @return HasMany<Repository, $this> */
    public function repositories(): HasMany
    {
        return $this->hasMany(Repository::class);
    }

    /** @return HasManyThrough<Build, Repository, $this> */
    public function builds(): HasManyThrough
    {
        return $this->hasManyThrough(Build::class, Repository::class);
    }

    /** @return HasManyThrough<RepositoryWebhookDelivery, Repository, $this> */
    public function webhookDeliveries(): HasManyThrough
    {
        return $this->hasManyThrough(RepositoryWebhookDelivery::class, Repository::class);
    }

    /** @return HasMany<Recipe, $this> */
    public function recipes(): HasMany
    {
        return $this->hasMany(Recipe::class);
    }

    /** @return HasMany<RecipeRating, $this> */
    public function recipeRatings(): HasMany
    {
        return $this->hasMany(RecipeRating::class);
    }

    /** @return HasMany<RecipeFavorite, $this> */
    public function recipeFavorites(): HasMany
    {
        return $this->hasMany(RecipeFavorite::class);
    }

    /** @return HasMany<RecipeReport, $this> */
    public function recipeReports(): HasMany
    {
        return $this->hasMany(RecipeReport::class);
    }

    /** @return HasMany<Event, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /** @return MorphMany<Event, $this> */
    public function accountEvents(): MorphMany
    {
        return $this->morphMany(Event::class, 'parentable');
    }

    /** @return HasMany<SignInEvent, $this> */
    public function signIns(): HasMany
    {
        return $this->hasMany(SignInEvent::class);
    }

    /** @return HasMany<ServerCommandExecution, $this> */
    public function commandExecutions(): HasMany
    {
        return $this->hasMany(ServerCommandExecution::class);
    }
}
