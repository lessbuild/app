<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScheduledTask extends Model
{
    protected $guarded = [];

    protected $hidden = ['command'];

    protected $casts = [
        'command' => 'encrypted',
        'timeout_seconds' => 'integer',
        'without_overlapping' => 'boolean',
        'alert_on_failure' => 'boolean',
        'is_enabled' => 'boolean',
        'last_queued_at' => 'datetime',
        'last_finished_at' => 'datetime',
    ];

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ScheduledTaskRun::class);
    }
}
