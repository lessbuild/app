<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScalingSchedule extends Model
{
    protected $guarded = [];

    protected $casts = ['is_enabled' => 'boolean', 'replicas' => 'integer', 'last_run_at' => 'datetime'];

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
