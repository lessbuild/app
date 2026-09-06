<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnvironmentResource extends Model
{
    public const TYPES = ['mysql', 'postgresql', 'redis', 'valkey', 'object_storage'];

    protected $guarded = [];

    protected $hidden = ['configuration'];

    protected $casts = ['configuration' => 'encrypted:array', 'is_managed' => 'boolean'];

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(DatabaseSnapshot::class);
    }

    public function databaseUsers(): HasMany
    {
        return $this->hasMany(DatabaseUser::class);
    }
}
