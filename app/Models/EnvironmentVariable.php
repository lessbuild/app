<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnvironmentVariable extends Model
{
    public const SCOPES = ['runtime', 'build', 'all'];

    protected $guarded = [];

    protected $hidden = ['value'];

    protected $casts = [
        'value' => 'encrypted',
        'is_secret' => 'boolean',
        'current_version' => 'integer',
        'rotated_at' => 'datetime',
        'rotation_due_at' => 'datetime',
    ];

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(EnvironmentVariableVersion::class)->latest('version');
    }
}
