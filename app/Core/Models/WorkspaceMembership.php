<?php

namespace App\Core\Models;

use App\Core\Database\CoreModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkspaceMembership extends CoreModel
{
    protected $fillable = [
        'workspace_id',
        'user_id',
        'role',
        'status',
        'invited_by_user_id',
        'invited_at',
        'joined_at',
        'expires_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'invited_at' => 'datetime',
            'joined_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function scopeCurrentlyActive(Builder $query): Builder
    {
        return $query
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->where(fn (Builder $membership): Builder => $membership
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()));
    }

    public function currentlyActive(): bool
    {
        return $this->status === 'active'
            && $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<PlatformUser, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'user_id');
    }

    /** @return HasMany<WorkspaceProductAccess, $this> */
    public function productAccess(): HasMany
    {
        return $this->hasMany(WorkspaceProductAccess::class, 'membership_id');
    }
}
