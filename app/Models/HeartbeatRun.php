<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\HeartbeatRunFactory;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $monitor_id
 * @property string $run_id
 * @property int $config_revision
 * @property string $status
 * @property string|null $terminal_signal
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $deadline_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Monitor $monitor
 */
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
#[UseFactory(HeartbeatRunFactory::class)]
class HeartbeatRun extends Model
{
    /** @use HasFactory<HeartbeatRunFactory> */
    use HasFactory;

    /** @return BelongsTo<Monitor, $this> */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class)->withTrashed();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['config_revision' => 'integer', 'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime', 'deadline_at' => 'immutable_datetime'];
    }
}
