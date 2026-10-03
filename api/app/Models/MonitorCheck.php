<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\MonitorCheckFactory;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $monitor_id
 * @property int $config_revision
 * @property string $location
 * @property string $status
 * @property string|null $outcome
 * @property string|null $reason
 * @property int|null $http_status
 * @property float|null $duration_ms
 * @property float|null $dns_ms
 * @property float|null $connect_ms
 * @property float|null $ttfb_ms
 * @property int $skipped_intervals
 * @property array<string, mixed>|null $details
 * @property array<string, mixed>|null $evidence
 * @property bool|null $scheduled_slot
 * @property CarbonImmutable $scheduled_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $lease_until
 * @property string|null $processing_token
 * @property string|null $queue_job_uuid
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Monitor $monitor
 */
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
#[UseFactory(MonitorCheckFactory::class)]
class MonitorCheck extends Model
{
    /** @use HasFactory<MonitorCheckFactory> */
    use HasFactory, HasUlids;

    /**
     * Queue bookkeeping and the private evidence never leave the server in serialised form.
     *
     * @var list<string>
     */
    protected $hidden = ['processing_token', 'queue_job_uuid', 'evidence', 'scheduled_slot'];

    /**
     * Get the monitor that ran the check, including archived ones.
     *
     * @return BelongsTo<Monitor, $this>
     */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class)->withTrashed();
    }

    /**
     * Get the attributes that should be cast.
     *
     * Encrypts `evidence` (record values seen during the check) and reads `details` as JSON.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'details' => 'array', 'evidence' => 'encrypted:array',
            'config_revision' => 'integer', 'http_status' => 'integer', 'skipped_intervals' => 'integer',
            'duration_ms' => 'float', 'dns_ms' => 'float', 'connect_ms' => 'float', 'ttfb_ms' => 'float',
            'scheduled_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime', 'lease_until' => 'immutable_datetime',
        ];
    }
}
