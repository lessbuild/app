<?php

namespace App\Core\Models;

use App\Core\Database\CoreModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectResource extends CoreModel
{
    protected $fillable = [
        'project_id',
        'environment_id',
        'product',
        'resource_type',
        'resource_id',
        'resource_public_id',
        'name',
        'status',
        'mapped_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'mapped_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<ProjectEnvironment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(ProjectEnvironment::class, 'environment_id');
    }
}
