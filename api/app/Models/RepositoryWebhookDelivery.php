<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One push the repository webhook received, deduplicated by the Git host's delivery ID.
 *
 * @property int $id
 * @property int $repository_id
 * @property string $delivery_id
 * @property string $status received, queued, pending (waiting for a running deploy), skipped (no relevant paths changed) or unavailable
 * @property string|null $revision
 * @property string|null $commit_message
 * @property list<string>|null $changed_paths
 * @property int|null $build_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Repository $repository
 * @property-read Build|null $build
 */
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class RepositoryWebhookDelivery extends Model
{
    use MassPrunable;

    /**
     * Deliveries are kept this many days; `model:prune` deletes older ones.
     *
     * @var int
     */
    public const RETENTION_DAYS = 30;

    /**
     * Get the repository the delivery was for.
     *
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /**
     * Get the deploy it started, if any.
     *
     * @return BelongsTo<Build, $this>
     */
    public function build(): BelongsTo
    {
        return $this->belongsTo(Build::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * Reads `changed_paths` as a JSON list.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['changed_paths' => 'array'];
    }

    /**
     * Get the entries old enough to delete.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }
}
