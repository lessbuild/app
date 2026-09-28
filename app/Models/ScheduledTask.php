<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\RunsOnCron;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A command run on a cron schedule in one of an environment's websites: in its current release, as `www-data`, with its
 * `.env`, under a timeout.
 *
 * @property int $id
 * @property string $environment_id
 * @property int $website_id
 * @property string|null $created_by
 * @property string $name
 * @property string $command encrypted
 * @property string $cron_expression
 * @property string $timezone
 * @property int $timeout_seconds
 * @property bool $without_overlapping a due run is skipped while the previous one is queued or running
 * @property bool $alert_on_failure members with Deploy access hear when it starts failing or recovers
 * @property bool $is_enabled
 * @property CarbonImmutable|null $last_queued_at
 * @property CarbonImmutable|null $last_finished_at
 * @property string|null $last_status succeeded or failed
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Environment $environment
 * @property-read Website $website
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ScheduledTaskRun> $runs
 */
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class ScheduledTask extends Model
{
    use RunsOnCron;

    /**
     * How many runs keep their history; older ones are deleted.
     *
     * @var int
     */
    public const KEEP_RUNS = 50;

    /**
     * Get the environment the task belongs to.
     *
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * Get the website whose current release the task runs in.
     *
     * @return BelongsTo<Website, $this>
     */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /**
     * Get the task's runs.
     *
     * @return HasMany<ScheduledTaskRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(ScheduledTaskRun::class);
    }

    /**
     * Get the column that records when the task was last queued.
     *
     * @return string
     */
    protected function lastRunColumn(): string
    {
        return 'last_queued_at';
    }

    /**
     * Get the attributes that should be cast.
     *
     * Encrypts the command, which can carry secrets, and reads the flags and timestamps.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'command' => 'encrypted', 'timeout_seconds' => 'integer', 'without_overlapping' => 'boolean', 'alert_on_failure' => 'boolean',
            'is_enabled' => 'boolean', 'last_queued_at' => 'immutable_datetime', 'last_finished_at' => 'immutable_datetime',
        ];
    }
}
