<?php

namespace App\Core\Models;

use App\Core\Database\CoreModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectConnection extends CoreModel
{
    protected $fillable = [
        'project_id',
        'source_resource_id',
        'target_resource_id',
        'source_environment_id',
        'target_environment_id',
        'capabilities',
        'status',
        'created_by_user_id',
        'last_succeeded_at',
        'last_error_code',
        'last_error_at',
        'disconnected_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'last_succeeded_at' => 'datetime',
            'last_error_at' => 'datetime',
            'disconnected_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<ProjectResource, $this> */
    public function sourceResource(): BelongsTo
    {
        return $this->belongsTo(ProjectResource::class, 'source_resource_id');
    }

    /** @return BelongsTo<ProjectResource, $this> */
    public function targetResource(): BelongsTo
    {
        return $this->belongsTo(ProjectResource::class, 'target_resource_id');
    }

    /** @return BelongsTo<ProjectEnvironment, $this> */
    public function sourceEnvironment(): BelongsTo
    {
        return $this->belongsTo(ProjectEnvironment::class, 'source_environment_id');
    }

    /** @return BelongsTo<ProjectEnvironment, $this> */
    public function targetEnvironment(): BelongsTo
    {
        return $this->belongsTo(ProjectEnvironment::class, 'target_environment_id');
    }

    /** @return BelongsTo<PlatformUser, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'created_by_user_id');
    }

    /** @return HasMany<ProjectConnectionEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ProjectConnectionEvent::class)->orderBy('occurred_at');
    }
}
