<?php

namespace App\Core\Models;

use App\Core\Database\CoreModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceDashboardView extends CoreModel
{
    protected $fillable = [
        'workspace_id',
        'visibility',
        'scope_key',
        'owner_user_id',
        'created_by_user_id',
        'name',
        'filters',
    ];

    protected function casts(): array
    {
        return ['filters' => 'array'];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<PlatformUser, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'owner_user_id');
    }

    /** @return BelongsTo<PlatformUser, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'created_by_user_id');
    }
}
