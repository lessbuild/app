<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DatabaseClone extends Model
{
    protected $guarded = [];

    protected $hidden = ['error'];

    protected $casts = ['transferred_bytes' => 'integer', 'started_at' => 'datetime', 'finished_at' => 'datetime'];

    public function source(): BelongsTo
    {
        return $this->belongsTo(EnvironmentResource::class, 'source_resource_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(EnvironmentResource::class, 'target_resource_id');
    }
}
