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
 * Deploys an environment's repositories on a cron schedule, through the environment's usual approval, lock and window.
 *
 * @property int $id
 * @property string $environment_id
 * @property string|null $created_by
 * @property string $name
 * @property string $cron_expression
 * @property string $timezone
 * @property bool $is_enabled
 * @property CarbonImmutable|null $last_run_at
 * @property string|null $last_result what the last run did, or why it deployed nothing
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Environment $environment
 * @property-read User|null $creator
 */
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class DeploymentSchedule extends Model
{
    use RunsOnCron;

    /**
     * Get the environment whose repositories it deploys.
     *
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * Get who created the schedule, recorded as the requester of its deploys.
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
     * Reads `is_enabled` as a boolean and `last_run_at` as an immutable date.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'last_run_at' => 'immutable_datetime'];
    }
}
