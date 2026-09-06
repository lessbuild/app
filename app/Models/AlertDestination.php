<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertDestination extends Model
{
    public const TYPES = ['webhook', 'slack', 'email', 'discord', 'teams', 'pagerduty'];

    public const EVENTS = ['failure', 'recovery'];

    protected $guarded = [];

    protected $hidden = ['endpoint', 'signing_secret'];

    protected $casts = [
        'endpoint' => 'encrypted',
        'signing_secret' => 'encrypted',
        'events' => 'array',
        'is_active' => 'boolean',
        'last_delivered_at' => 'datetime',
        'last_failed_at' => 'datetime',
    ];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
