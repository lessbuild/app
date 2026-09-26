<?php

namespace App\Core\Models;

use App\Core\Database\CoreModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceDashboardSelection extends CoreModel
{
    protected $fillable = [
        'workspace_id',
        'user_id',
        'view_id',
    ];

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

    /** @return BelongsTo<WorkspaceDashboardView, $this> */
    public function view(): BelongsTo
    {
        return $this->belongsTo(WorkspaceDashboardView::class, 'view_id');
    }
}
