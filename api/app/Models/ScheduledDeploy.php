<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A one-off deploy booked for later. Pending until its time, then done (with the deploy it started, or why it
 * couldn't) or cancelled.
 *
 * @property int $id
 * @property int $repository_id
 * @property Carbon $run_at
 * @property string|null $git_ref the branch, tag or commit to deploy; null for the repository's branch
 * @property string $status pending, running, done or cancelled
 * @property int|null $build_id
 * @property string|null $result
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Repository $repository
 * @property-read User|null $creator
 */
class ScheduledDeploy extends Model
{
    public const STATUS_PENDING = 'pending';

    /**
     * Get the repository it deploys.
     *
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /**
     * Get who booked it; the deploy runs as them.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * Reads the time as a date.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['run_at' => 'datetime'];
    }
}
