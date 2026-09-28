<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One run of a scheduled task, on schedule or by hand, with its output.
 *
 * @property int $id
 * @property int $scheduled_task_id
 * @property string|null $requested_by null for scheduled runs
 * @property string $status queued, running, succeeded or failed
 * @property string|null $output the last 64 KB of output (encrypted)
 * @property int|null $exit_code
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property int|null $duration_ms
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ScheduledTask $task
 * @property-read User|null $requester
 */
#[Hidden(['output'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class ScheduledTaskRun extends Model
{
    /**
     * Get the task this is a run of.
     *
     * @return BelongsTo<ScheduledTask, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(ScheduledTask::class, 'scheduled_task_id');
    }

    /**
     * Get who ran it by hand, if anyone.
     *
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * Determine whether the run hasn't finished yet.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return in_array($this->status, ['queued', 'running'], true);
    }

    /**
     * Get the attributes that should be cast.
     *
     * Encrypts the output and reads the timestamps as immutable dates.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['output' => 'encrypted', 'exit_code' => 'integer', 'duration_ms' => 'integer', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }
}
