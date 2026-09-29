<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A request on the public roadmap, written up from feedback, with a status and people's votes.
 *
 * @property int $id
 * @property string $title
 * @property string|null $description
 * @property string $status one of STATUSES
 * @property int $votes_count
 * @property Carbon|null $shipped_at
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, User> $voters
 */
class FeatureRequest extends Model
{
    /**
     * The statuses, in the order the roadmap shows them, with their labels.
     *
     * @var array<string, string>
     */
    public const STATUSES = [
        'in_progress' => 'In progress',
        'planned' => 'Planned',
        'under_review' => 'Under consideration',
        'shipped' => 'Shipped',
        'declined' => 'Not planned',
    ];

    /**
     * Get the people who voted for it.
     *
     * @return BelongsToMany<User, $this>
     */
    public function voters(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'feature_request_votes')->withPivot('created_at');
    }

    /**
     * Get the feedback it was written up from.
     *
     * @return HasMany<Feedback, $this>
     */
    public function feedback(): HasMany
    {
        return $this->hasMany(Feedback::class);
    }

    /**
     * Get the status as people read it.
     *
     * @return string
     */
    public function statusLabel(): string
    {
        return __(self::STATUSES[$this->status] ?? $this->status);
    }

    /**
     * Get the attributes that should be cast.
     *
     * Reads when it shipped as a date.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['shipped_at' => 'datetime', 'votes_count' => 'integer'];
    }
}
