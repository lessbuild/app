<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    protected $guarded = [];

    protected $casts = [
        'preview_enabled' => 'boolean',
        'preview_ttl_hours' => 'integer',
        'workflow_yaml' => 'encrypted',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function environments(): HasMany
    {
        return $this->hasMany(Environment::class);
    }

    public function previews(): HasMany
    {
        return $this->hasMany(PreviewDeployment::class);
    }
}
