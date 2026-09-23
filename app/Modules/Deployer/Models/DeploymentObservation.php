<?php

namespace App\Modules\Deployer\Models;

use App\Modules\Deployer\Database\DeployerModel;

use App\Modules\Deployer\Enums\DeploymentObservationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeploymentObservation extends DeployerModel
{
    public const STATUS_PENDING = DeploymentObservationStatus::Pending->value;

    public const STATUS_OBSERVING = DeploymentObservationStatus::Observing->value;

    public const STATUS_HEALTHY = DeploymentObservationStatus::Healthy->value;

    public const STATUS_FAILED = DeploymentObservationStatus::Failed->value;

    public const STATUS_EXPIRED = DeploymentObservationStatus::Expired->value;

    public const STATUS_SUPERSEDED = DeploymentObservationStatus::Superseded->value;

    public const ACTIVE_STATUSES = DeploymentObservationStatus::ACTIVE_VALUES;

    public const TERMINAL_STATUSES = DeploymentObservationStatus::TERMINAL_VALUES;

    protected $guarded = [];

    protected $hidden = ['claim_token'];

    protected $casts = [
        'duration_minutes' => 'integer',
        'successful_checks' => 'integer',
        'attempts' => 'integer',
        'last_http_status' => 'integer',
        'last_duration_ms' => 'integer',
        'started_at' => 'datetime',
        'last_checked_at' => 'datetime',
        'deadline_at' => 'datetime',
        'next_check_at' => 'datetime',
        'lease_expires_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /** @return DeploymentObservationStatus|null The known finite state, or null for a legacy value. */
    public function statusEnum(): ?DeploymentObservationStatus
    {
        $status = $this->getAttribute('status');

        return $status instanceof DeploymentObservationStatus
            ? $status
            : (is_string($status) ? DeploymentObservationStatus::tryFrom($status) : null);
    }

    /** @return BelongsTo<Build, $this> */
    public function build(): BelongsTo
    {
        return $this->belongsTo(Build::class);
    }

    /** @return BelongsTo<Website, $this> */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
