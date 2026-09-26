<?php

namespace App\Core\Models;

use App\Core\Database\CoreModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceProjectPin extends CoreModel
{
    protected $fillable = [
        'workspace_id',
        'project_id',
        'visibility',
        'scope_key',
        'owner_user_id',
        'created_by_user_id',
    ];

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<PlatformUser, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'owner_user_id');
    }
}
