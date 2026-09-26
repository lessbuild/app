<?php

namespace App\Core\Models;

use App\Core\Database\CoreModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceMembershipEvent extends CoreModel
{
    protected $table = 'workspace_membership_events';

    public $timestamps = false;

    protected $fillable = [
        'workspace_id',
        'membership_id',
        'actor_user_id',
        'subject_user_id',
        'event',
        'previous_role',
        'new_role',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<WorkspaceMembership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(WorkspaceMembership::class, 'membership_id');
    }

    /** @return BelongsTo<PlatformUser, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'actor_user_id');
    }

    /** @return BelongsTo<PlatformUser, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'subject_user_id');
    }
}
