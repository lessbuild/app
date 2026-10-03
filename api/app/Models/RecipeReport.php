<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RecipeReportReason;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Someone's report that a gallery recipe is harmful, broken, spam or otherwise wrong, which the publisher resolves.
 *
 * @property int $id
 * @property int $recipe_id
 * @property string $user_id the reporter
 * @property RecipeReportReason $reason
 * @property string|null $details
 * @property string $status open or resolved
 * @property string|null $resolution_note the publisher's answer, shown to the reporter
 * @property string|null $resolved_by
 * @property CarbonImmutable|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Recipe $recipe
 * @property-read User $user
 * @property-read User|null $resolver
 */
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class RecipeReport extends Model
{
    /**
     * Get the reported recipe.
     *
     * @return BelongsTo<Recipe, $this>
     */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /**
     * Get the reporter.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get who resolved it.
     *
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * Reads the reason as a RecipeReportReason and `resolved_at` as an immutable date.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['reason' => RecipeReportReason::class, 'resolved_at' => 'immutable_datetime'];
    }
}
