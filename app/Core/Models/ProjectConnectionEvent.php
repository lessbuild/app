<?php

namespace App\Core\Models;

use App\Core\Database\CoreModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectConnectionEvent extends CoreModel
{
    protected $fillable = [
        'project_connection_id',
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

    /** @return BelongsTo<ProjectConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(ProjectConnection::class, 'project_connection_id');
    }

    /** @return BelongsTo<PlatformUser, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'actor_user_id');
    }
}
