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

    public function receivesNotification(string $category, string $event): bool
    {
        $preferences = $this->notification_preferences ?? [];

        return in_array($category, $preferences['categories'] ?? ['website', 'server', 'deployment', 'provider', 'security', 'recipe', 'metric', 'scheduled_task'], true)
            && ($event !== 'recovery' || ($preferences['recoveries'] ?? true));
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(OrganizationInvitation::class);
    }

    public function providers(): HasMany
    {
        return $this->hasMany(Provider::class);
    }

    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    public function websites(): HasMany
    {
        return $this->hasMany(Website::class);
    }

    public function repositories(): HasMany
    {
        return $this->hasMany(Repository::class);
    }

    public function recipes(): HasMany
    {
        return $this->hasMany(Recipe::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function alertDestinations(): HasMany
    {
        return $this->hasMany(AlertDestination::class);
    }

    public function statusPages(): HasMany
    {
        return $this->hasMany(StatusPage::class);
    }

    public function backupDestinations(): HasMany
    {
        return $this->hasMany(BackupDestination::class);
    }

    public function metricAlertRules(): HasMany
    {
        return $this->hasMany(MetricAlertRule::class);
    }

    public function operationalIncidents(): HasMany
    {
        return $this->hasMany(OperationalIncident::class);
    }

    public function loadBalancers(): HasMany
    {
        return $this->hasMany(LoadBalancer::class);
    }

    public function productFeedback(): HasMany
    {
        return $this->hasMany(ProductFeedback::class);
    }

    public function roleFor(User $user): ?string
    {
        if ((int) $this->owner_id === (int) $user->id) {
            return 'owner';
        }

        return $this->members()->whereKey($user->id)->first()?->pivot->role;
    }

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
