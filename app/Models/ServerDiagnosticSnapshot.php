<?php

namespace App\Models;

use App\Data\OperationalDiagnosticReport;
use App\Enums\ServerDiagnosticFailureStage;
use App\Enums\ServerDiagnosticStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerDiagnosticSnapshot extends Model
{
    public const STATUS_QUEUED = ServerDiagnosticStatus::Queued->value;

    public const STATUS_RUNNING = ServerDiagnosticStatus::Running->value;

    public const STATUS_READY = ServerDiagnosticStatus::Ready->value;

    public const STATUS_FAILED = ServerDiagnosticStatus::Failed->value;

    public const ACTIVE_STATUSES = ServerDiagnosticStatus::ACTIVE_VALUES;

    public const TERMINAL_STATUSES = ServerDiagnosticStatus::TERMINAL_VALUES;

    protected $guarded = [];

    protected $hidden = ['attempt_token'];

    protected $casts = [
        'checks' => 'array',
        'attempt' => 'integer',
        'lease_expires_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** Return the known lifecycle state without changing its stored string value. */
    public function statusEnum(): ?ServerDiagnosticStatus
    {
        $status = $this->getAttribute('status');

        return $status instanceof ServerDiagnosticStatus
            ? $status
            : (is_string($status) ? ServerDiagnosticStatus::tryFrom($status) : null);
    }

    /** Return whether the snapshot is queued or currently running. */
    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    /** Return whether the execution lease has expired. */
    public function leaseExpired(): bool
    {
        return $this->lease_expires_at?->isPast() ?? false;
    }

    /** Rehydrate the safe typed checks retained by this snapshot. */
    public function report(): ?OperationalDiagnosticReport
    {
        if (! is_array($this->checks)) {
            return null;
        }

        return OperationalDiagnosticReport::fromStoredChecks($this->checks);
    }

    /** Return the finite failure stage without changing the persisted value. */
    public function failureStageEnum(): ?ServerDiagnosticFailureStage
    {
        return is_string($this->failure_stage)
            ? ServerDiagnosticFailureStage::tryFrom($this->failure_stage)
            : null;
    }
}
