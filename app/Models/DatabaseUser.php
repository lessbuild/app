<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DatabaseUser extends Model
{
    protected $guarded = [];

    protected $hidden = ['password'];

    protected $casts = ['password' => 'encrypted', 'expires_at' => 'datetime', 'applied_at' => 'datetime'];

    /** @return BelongsTo<EnvironmentResource, $this> */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(EnvironmentResource::class, 'environment_resource_id');
    }
}
