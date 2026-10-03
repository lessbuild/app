<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Someone's 1–5 rating of a gallery recipe, given after their account installed it.
 *
 * @property int $id
 * @property int $recipe_id
 * @property string $user_id
 * @property int $rating 1 to 5
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Recipe $recipe
 * @property-read User $user
 */
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class RecipeRating extends Model
{
    /**
     * Get the recipe.
     *
     * @return BelongsTo<Recipe, $this>
     */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /**
     * Get the person.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * Reads the rating as an integer.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['rating' => 'integer'];
    }
}
