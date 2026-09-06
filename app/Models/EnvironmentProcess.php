<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnvironmentProcess extends Model
{
    public const TYPES = ['worker', 'scheduler'];

    protected $guarded = [];

    protected $hidden = ['command'];

    protected $casts = ['command' => 'encrypted', 'replicas' => 'integer', 'restart_delay_seconds' => 'integer', 'is_enabled' => 'boolean'];

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }
}
