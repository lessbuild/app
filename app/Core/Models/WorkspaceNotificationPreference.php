<?php

namespace App\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceNotificationPreference extends Model
{
    protected $connection = 'core';

    protected $guarded = [];

    protected $casts = ['enabled' => 'boolean'];

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<PlatformUser, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
