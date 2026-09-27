<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\QueueWorkerFactory;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $monitor_id
 * @property string $worker_id
 * @property int $config_revision
 * @property int $last_sequence
 * @property string $status
 * @property string|null $job_id
 * @property CarbonImmutable|null $job_started_at
 * @property CarbonImmutable $last_seen_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Monitor $monitor
 */
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
#[UseFactory(QueueWorkerFactory::class)]
class QueueWorker extends Model
{
    /** @use HasFactory<QueueWorkerFactory> */
    use HasFactory;

    /** @return BelongsTo<Monitor, $this> */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class)->withTrashed();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['config_revision' => 'integer', 'last_sequence' => 'integer',
            'job_started_at' => 'immutable_datetime', 'last_seen_at' => 'immutable_datetime'];
    }
}
