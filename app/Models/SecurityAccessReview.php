<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A completed access review: who reviewed the account's members, API tokens and SSH access, what they saw, and what
 * they removed. Kept as evidence for audits.
 *
 * @property int $id
 * @property string $account_id
 * @property string $project_id
 * @property string|null $reviewed_by
 * @property array{members: int, tokens: int, grants: int, removed: list<string>} $summary
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $reviewer
 */
final class SecurityAccessReview extends Model
{
    /**
     * How many days an access review lasts before the next is due.
     *
     * @var int
     */
    public const DUE_AFTER_DAYS = 90;

    /**
     * The attributes that can't be mass assigned: all of them; reviews are written with forceFill.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * Get the attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['summary' => 'array'];
    }

    /**
     * Get who completed the review.
     *
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
