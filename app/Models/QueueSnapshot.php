<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\QueueSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $monitor_id
 * @property string $snapshot_id
 * @property int $config_revision
 * @property string $payload_hash
 * @property CarbonImmutable $observed_at
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable $valid_until
 * @property bool $applied
 * @property int $pending
 * @property int|null $delayed
 * @property int|null $reserved
 * @property int|null $failed
 * @property int|null $oldest_wait_seconds
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Monitor $monitor
 */
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
#[UseFactory(QueueSnapshotFactory::class)]
class QueueSnapshot extends Model
{
    /** @use HasFactory<QueueSnapshotFactory> */
    use HasFactory;

    /**
     * The payload hash, used to spot repeated reports, isn't serialised.
     */
    protected $hidden = ['payload_hash'];

    /**
     * The queue monitor the report was sent to, including archived ones.
     *
     * @return BelongsTo<Monitor, $this>
     */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class)->withTrashed();
    }

    /**
     * Plain columns; dates come back as Carbon.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['config_revision' => 'integer', 'applied' => 'boolean',
            'pending' => 'integer', 'delayed' => 'integer', 'reserved' => 'integer', 'failed' => 'integer', 'oldest_wait_seconds' => 'integer',
            'observed_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime', 'valid_until' => 'immutable_datetime'];
    }
}
