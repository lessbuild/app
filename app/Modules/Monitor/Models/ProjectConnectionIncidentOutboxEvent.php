<?php

namespace App\Modules\Monitor\Models;

use App\Modules\Monitor\Database\MonitorModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

final class ProjectConnectionIncidentOutboxEvent extends MonitorModel
{
    use HasUlids;

    public const OPENED = 'monitor.incident_opened';

    public const ACKNOWLEDGED = 'monitor.incident_acknowledged';

    public const RESOLVED = 'monitor.incident_resolved';

    protected $table = 'project_connection_incident_outbox_events';

    protected $fillable = [
        'event_type', 'event_version', 'source_incident_id', 'source_environment_id', 'payload',
        'status', 'attempts', 'available_at', 'dispatched_at', 'last_error_code', 'last_error_at',
    ];

    protected function casts(): array
    {
        return [
            'event_version' => 'integer',
            'payload' => 'array',
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'last_error_at' => 'datetime',
        ];
    }
}
