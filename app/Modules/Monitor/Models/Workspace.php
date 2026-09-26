<?php

namespace App\Modules\Monitor\Models;

use App\Modules\Monitor\Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Modules\Monitor\Database\MonitorModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug'])]
class Workspace extends Model
{
    public const ASSIGNABLE_ROLES = ['admin', 'member', 'viewer'];

    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory;

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

    /** @return HasMany<Application, $this> */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    /** @return HasMany<WorkspaceInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(WorkspaceInvitation::class);
    }

    /** @return HasMany<StatusPage, $this> */
    public function statusPages(): HasMany
    {
        return $this->hasMany(StatusPage::class);
    }

    /** @return HasMany<MaintenanceWindow, $this> */
    public function maintenanceWindows(): HasMany
    {
        return $this->hasMany(MaintenanceWindow::class);
    }

    /** @return HasMany<AuditLog, $this> */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    /** @return HasMany<BillingEvent, $this> */
    public function billingEvents(): HasMany
    {
        return $this->hasMany(BillingEvent::class);
    }

    /** @return HasMany<IssueDigestPreference, $this> */
    public function issueDigestPreferences(): HasMany
    {
        return $this->hasMany(IssueDigestPreference::class);
    }

    /** @return HasMany<IssueDigestDelivery, $this> */
    public function issueDigestDeliveries(): HasMany
    {
        return $this->hasMany(IssueDigestDelivery::class);
    }

    /** @return HasMany<UsageAlertDelivery, $this> */
    public function usageAlertDeliveries(): HasMany
    {
        return $this->hasMany(UsageAlertDelivery::class);
    }

    /** @return HasMany<Dashboard, $this> */
    public function dashboards(): HasMany
    {
        return $this->hasMany(Dashboard::class);
    }

    public function roleFor(User $user): ?string
    {
        return $this->members()->whereKey($user->id)->first()?->pivot->role;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'billing_cancel_at_period_end' => 'boolean',
            'billing_period_ends_at' => 'immutable_datetime',
            'billing_updated_at' => 'immutable_datetime',
            'billing_checkout_started_at' => 'immutable_datetime',
            'billing_event_created_at' => 'integer',
        ];
    }
}
