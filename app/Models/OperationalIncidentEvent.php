<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationalIncidentEvent extends Model
{
    protected $guarded = [];

    protected $hidden = ['message', 'metadata'];

    protected $casts = ['message' => 'encrypted', 'metadata' => 'encrypted:array', 'occurred_at' => 'datetime'];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(OperationalIncident::class, 'operational_incident_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
