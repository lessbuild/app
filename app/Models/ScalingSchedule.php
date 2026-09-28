<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\RunsOnCron;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Sets how many worker replicas an environment runs, on a cron schedule, and applies it at once.
 *
 * @property int $id
 * @property string $environment_id
 * @property string|null $created_by
 * @property string $name
 * @property int $replicas clamped to the environment's minimum and maximum when applied
 * @property string $cron_expression
 * @property string $timezone
 * @property bool $is_enabled
 * @property CarbonImmutable|null $last_run_at
 * @property string|null $last_result what the last run set
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Environment $environment
 * @property-read User|null $creator
 */
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class ScalingSchedule extends Model
{
    use RunsOnCron;

    /**
     * Get the environment it scales.
     *
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * Get who created the schedule.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the column that records when the schedule last ran.
     *
     * @return string
     */
    protected function lastRunColumn(): string
    {
        return 'last_run_at';
    }

    /**
     * Get the attributes that should be cast.
     *
     * Reads `is_enabled` as a boolean, `replicas` as an integer and `last_run_at` as an immutable date.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'replicas' => 'integer', 'last_run_at' => 'immutable_datetime'];
    }
}
