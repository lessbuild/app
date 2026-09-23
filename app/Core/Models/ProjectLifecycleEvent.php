<?php

namespace App\Core\Models;

use App\Core\Database\CoreModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectLifecycleEvent extends CoreModel
{
    protected $fillable = [
        'project_id',
        'actor_user_id',
        'event_type',
        'details',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<PlatformUser, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'actor_user_id');
    }
}
