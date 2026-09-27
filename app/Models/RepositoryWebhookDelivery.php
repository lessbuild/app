<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
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
    /** @return BelongsTo<Repository, $this> */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /** @return BelongsTo<Build, $this> */
    public function build(): BelongsTo
    {
        return $this->belongsTo(Build::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['changed_paths' => 'array'];
    }
}
