<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BackupDestination extends Model
{
    protected $guarded = [];

    protected $hidden = ['access_key', 'secret_key', 'repository_password'];

    protected $casts = [
        'access_key' => 'encrypted',
        'secret_key' => 'encrypted',
        'repository_password' => 'encrypted',
        'is_active' => 'boolean',
        'last_verified_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(WebsiteBackupSchedule::class);
    }
}
