<?php

namespace App\Core\Models;

use App\Core\Database\CoreModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workspace extends CoreModel
{
    protected $fillable = [
        'owner_user_id',
        'name',
        'slug',
        'status',
        'settings',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PlatformUser, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'owner_user_id');
    }

    /** @return HasMany<WorkspaceMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(WorkspaceMembership::class);
    }

    /** @return HasMany<WorkspaceInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(WorkspaceInvitation::class);
    }

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /** @return HasMany<ProductSubscription, $this> */
    public function productSubscriptions(): HasMany
    {
        return $this->hasMany(ProductSubscription::class);
    }

    /** @return HasMany<CurrentProductSubscription, $this> */
    public function currentProductSubscriptions(): HasMany
    {
        return $this->hasMany(CurrentProductSubscription::class);
    }

    /** @return HasMany<ProductBillingEvent, $this> */
    public function productBillingEvents(): HasMany
    {
        return $this->hasMany(ProductBillingEvent::class);
    }

    /** @return HasMany<WorkspaceDashboardView, $this> */
    public function views(): HasMany
    {
        return $this->hasMany(WorkspaceDashboardView::class);
    }

    /** @return HasMany<WorkspaceProjectPin, $this> */
    public function projectPins(): HasMany
    {
        return $this->hasMany(WorkspaceProjectPin::class);
    }
}
