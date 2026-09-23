<?php

namespace App\Modules\Monitor\Models;

use App\Modules\Monitor\Data\Telemetry\AlertDeliveryStatus;
use App\Modules\Monitor\Database\Factories\AlertDeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Modules\Monitor\Database\MonitorModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class AlertDelivery extends Model
{
    /** @use HasFactory<AlertDeliveryFactory> */
    use HasFactory, HasUlids;

    protected $hidden = ['payload', 'processing_token', 'queue_job_uuid'];

    /** @param Builder<AlertDelivery> $query */
    #[Scope]
    protected function forWorkspace(Builder $query, Workspace $workspace): void
    {
        $query->whereBelongsTo($workspace);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<AlertDestination, $this> */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(AlertDestination::class, 'alert_destination_id')->withTrashed();
    }

    /** @return BelongsTo<Incident, $this> */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /** @return HasMany<AlertDeliveryAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(AlertDeliveryAttempt::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => AlertDeliveryStatus::class, 'payload' => 'encrypted:array',
            'generation' => 'integer', 'attempt_count' => 'integer', 'cycle_attempts' => 'integer',
            'target_revision' => 'integer', 'http_status' => 'integer',
            'next_attempt_at' => 'immutable_datetime', 'accepted_at' => 'immutable_datetime', 'failed_at' => 'immutable_datetime',
        ];
    }
}
