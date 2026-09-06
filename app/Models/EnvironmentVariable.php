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

    /** @return BelongsTo<Environment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** @return HasMany<EnvironmentVariableVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(EnvironmentVariableVersion::class)->latest('version');
    }
}
