<?php

namespace App\Core\Models;

use App\Core\Database\CoreModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectEnvironment extends CoreModel
{
    protected $fillable = [
        'project_id',
        'created_by_user_id',
        'name',
        'slug',
        'environment_type',
        'status',
        'metadata',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<ProjectResource, $this> */
    public function resources(): HasMany
    {
        return $this->hasMany(ProjectResource::class, 'environment_id');
    }
}
