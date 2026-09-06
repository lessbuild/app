<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetricAlertRule extends Model
{
    public const METRICS = ['cpu_percent', 'memory_percent', 'disk_percent', 'load_1m', 'process_count'];

    protected $guarded = [];

    protected $casts = [
        'threshold' => 'float',
        'consecutive_breaches' => 'integer',
        'breach_count' => 'integer',
        'cooldown_minutes' => 'integer',
        'is_enabled' => 'boolean',
        'is_alerting' => 'boolean',
        'last_evaluated_at' => 'datetime',
        'last_triggered_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
