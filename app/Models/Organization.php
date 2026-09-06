<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    public const ROLES = ['admin', 'operator', 'developer', 'auditor', 'billing', 'viewer'];

    protected $guarded = [];

    protected $casts = [
        'notification_preferences' => 'array',
        'allowed_ip_ranges' => 'encrypted:array',
        'allowed_email_domains' => 'array',
        'require_two_factor' => 'boolean',
        'session_idle_minutes' => 'integer',
        'monthly_infrastructure_budget' => 'decimal:2',
        'sso_configuration' => 'encrypted:array',
        'sso_enforced' => 'boolean',
    ];

    /**
     * Evaluate category and recovery preferences for an operational notification.
     *
     * @param  string  $category  The operational notification category.
     * @param  string  $event  The event name; recovery notifications have a separate preference.
     * @return bool Whether this workspace accepts the notification.
     */
    public function receivesNotification(string $category, string $event): bool
    {
        $preferences = $this->notification_preferences ?? [];

        return in_array($category, $preferences['categories'] ?? ['website', 'server', 'deployment', 'provider', 'security', 'recipe', 'metric', 'scheduled_task'], true)
            && ($event !== 'recovery' || ($preferences['recoveries'] ?? true));
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    /** @return HasMany<OrganizationInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(OrganizationInvitation::class);
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

    /** @return HasMany<Website, $this> */
    public function websites(): HasMany
    {
        return $this->hasMany(Website::class);
    }

    /** @return HasMany<Repository, $this> */
    public function repositories(): HasMany
    {
        return $this->hasMany(Repository::class);
    }

    /** @return HasMany<Recipe, $this> */
    public function recipes(): HasMany
    {
        return $this->hasMany(Recipe::class);
    }

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /** @return HasMany<AlertDestination, $this> */
    public function alertDestinations(): HasMany
    {
        return $this->hasMany(AlertDestination::class);
    }

    /** @return HasMany<StatusPage, $this> */
    public function statusPages(): HasMany
    {
        return $this->hasMany(StatusPage::class);
    }

    /** @return HasMany<BackupDestination, $this> */
    public function backupDestinations(): HasMany
    {
        return $this->hasMany(BackupDestination::class);
    }

    /** @return HasMany<MetricAlertRule, $this> */
    public function metricAlertRules(): HasMany
    {
        return $this->hasMany(MetricAlertRule::class);
    }

    /** @return HasMany<OperationalIncident, $this> */
    public function operationalIncidents(): HasMany
    {
        return $this->hasMany(OperationalIncident::class);
    }

    /** @return HasMany<LoadBalancer, $this> */
    public function loadBalancers(): HasMany
    {
        return $this->hasMany(LoadBalancer::class);
    }

    /** @return HasMany<ProductFeedback, $this> */
    public function productFeedback(): HasMany
    {
        return $this->hasMany(ProductFeedback::class);
    }

    /**
     * Resolve current workspace membership, recognizing the owner without a pivot lookup.
     *
     * @param  User  $user  The account whose membership is checked.
     * @return ?string The membership role, or null when the account has no access.
     */
    public function roleFor(User $user): ?string
    {
        if ((int) $this->owner_id === (int) $user->id) {
            return 'owner';
        }

        return $this->members()->whereKey($user->id)->first()?->pivot->role;
    }

    /**
     * Check a workspace ability against the account's current role.
     *
     * @param  User  $user  The account requesting access.
     * @param  string  $ability  The workspace ability to evaluate.
     * @return bool Whether the role permits the requested ability.
     */
    public function permits(User $user, string $ability): bool
    {
        $role = $this->roleFor($user);

        return match ($ability) {
            'manage' => in_array($role, ['owner', 'admin'], true),
            'deploy' => in_array($role, ['owner', 'admin', 'operator', 'developer'], true),
            'operate' => in_array($role, ['owner', 'admin', 'operator'], true),
            'audit' => in_array($role, ['owner', 'admin', 'auditor'], true),
            'billing' => in_array($role, ['owner', 'billing'], true),
            'view' => $role !== null,
            default => false,
        };
    }
}
