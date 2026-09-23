<?php

namespace App\Modules\Deployer\Models;

use App\Modules\Deployer\Database\DeployerModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

final class DeploymentSucceededOutboxEvent extends DeployerModel
{
    use HasUlids;

    public const EVENT_TYPE = 'deployer.deployment_succeeded';

    protected $table = 'deployment_succeeded_outbox_events';

    protected $fillable = [
        'event_type',
        'event_version',
        'source_build_id',
        'source_project_id',
        'source_environment_id',
        'payload',
        'status',
        'attempts',
        'available_at',
        'dispatched_at',
        'last_error_code',
        'last_error_at',
    ];

    protected function casts(): array
    {
        return [
            'event_version' => 'integer',
            'source_build_id' => 'integer',
            'source_project_id' => 'integer',
            'source_environment_id' => 'integer',
            'payload' => 'array',
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'last_error_at' => 'datetime',
        ];
    }
}
