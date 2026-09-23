<?php

namespace App\Core\Models;

use App\Core\Database\CoreModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectConnectionDelivery extends CoreModel
{
    protected $fillable = [
        'project_connection_id',
        'source_event_id',
        'event_type',
        'event_version',
        'payload',
        'status',
        'attempts',
        'available_at',
        'last_attempted_at',
        'delivered_at',
        'last_error_code',
        'last_error_at',
    ];

    protected function casts(): array
    {
        return [
            'event_version' => 'integer',
            'payload' => 'array',
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'last_attempted_at' => 'datetime',
            'delivered_at' => 'datetime',
            'last_error_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ProjectConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(ProjectConnection::class, 'project_connection_id');
    }
}
