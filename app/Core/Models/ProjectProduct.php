<?php

namespace App\Core\Models;

use App\Core\Database\CoreModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectProduct extends CoreModel
{
    protected $fillable = [
        'project_id',
        'product',
        'status',
        'requested_by_user_id',
        'activated_at',
        'last_error_code',
        'last_error_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'activated_at' => 'datetime',
            'last_error_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<PlatformUser, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'requested_by_user_id');
    }
}
