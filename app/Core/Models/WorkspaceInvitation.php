<?php

namespace App\Core\Models;

use App\Core\Database\CoreModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceInvitation extends CoreModel
{
    protected $fillable = [
        'workspace_id',
        'invited_by_user_id',
        'email',
        'email_normalized',
        'role',
        'token_hash',
        'status',
        'expires_at',
        'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<PlatformUser, $this> */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'invited_by_user_id');
    }
}
