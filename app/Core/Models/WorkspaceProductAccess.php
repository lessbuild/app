<?php

namespace App\Core\Models;

use App\Core\Database\CoreModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceProductAccess extends CoreModel
{
    protected $fillable = [
        'membership_id',
        'product',
        'role',
        'status',
        'granted_by_user_id',
        'granted_at',
        'expires_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<WorkspaceMembership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(WorkspaceMembership::class, 'membership_id');
    }

    /** @return BelongsTo<PlatformUser, $this> */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'granted_by_user_id');
    }
}
