<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DatabaseSnapshot extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['size_bytes' => 'integer', 'active_connections' => 'integer', 'slow_queries' => 'integer', 'schema_tables' => 'array', 'collected_at' => 'datetime'];

    public function resource(): BelongsTo
    {
        return $this->belongsTo(EnvironmentResource::class, 'environment_resource_id');
    }
}
