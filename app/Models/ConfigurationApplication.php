<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The receipt of applying a review: its local changes happened at `locally_applied_at`; its deploys are operations
 * (its own, or earlier identical ones it refers to). Status: locally_applied, awaiting_dispatch, deploying,
 * awaiting_approval, needs_attention, succeeded or remote_failed.
 *
 * @property int $id
 * @property int $configuration_review_id
 * @property string $status
 * @property CarbonImmutable|null $locally_applied_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ConfigurationReview $review
 */
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class ConfigurationApplication extends Model
{
    /**
     * The review that was applied.
     *
     * @return BelongsTo<ConfigurationReview, $this>
     */
    public function review(): BelongsTo
    {
        return $this->belongsTo(ConfigurationReview::class, 'configuration_review_id');
    }

    /**
     * The deploys and changes this application started.
     *
     * @return HasMany<ConfigurationOperation, $this>
     */
    public function operations(): HasMany
    {
        return $this->hasMany(ConfigurationOperation::class);
    }

    /**
     * Operations from earlier applications that this one waits on or retries.
     *
     * @return BelongsToMany<ConfigurationOperation, $this>
     */
    public function referencedOperations(): BelongsToMany
    {
        return $this->belongsToMany(ConfigurationOperation::class, 'configuration_operation_receipts');
    }

    /**
     * Everything that decides this application's status: its own operations and the ones it references.
     *
     * @return Builder<ConfigurationOperation> Its own operations and the earlier ones it refers to.
     */
    public function relatedOperations(): Builder
    {
        return ConfigurationOperation::query()->where(fn (Builder $query) => $query->where('configuration_application_id', $this->id)
            ->orWhereIn('id', $this->referencedOperations()->select('configuration_operations.id')));
    }

    /**
     * Plain columns; dates come back as Carbon.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['locally_applied_at' => 'immutable_datetime'];
    }
}
