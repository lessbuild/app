<?php

namespace App\Core\Models;

use App\Core\Database\CoreModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends CoreModel
{
    protected $fillable = [
        'workspace_id',
        'created_by_user_id',
        'name',
        'slug',
        'status',
        'description',
        'metadata',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return HasMany<ProjectMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(ProjectMembership::class);
    }

    /** @return HasMany<ProjectEnvironment, $this> */
    public function environments(): HasMany
    {
        return $this->hasMany(ProjectEnvironment::class);
    }

    /** @return HasMany<ProjectProduct, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(ProjectProduct::class);
    }

    /** @return HasMany<ProjectResource, $this> */
    public function resources(): HasMany
    {
        return $this->hasMany(ProjectResource::class);
    }

    /** @return HasMany<ProjectConnection, $this> */
    public function connections(): HasMany
    {
        return $this->hasMany(ProjectConnection::class);
    }
}
