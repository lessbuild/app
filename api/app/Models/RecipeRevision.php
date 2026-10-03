<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved version of a recipe: what it said, who saved it, and why.
 *
 * @property int $id
 * @property int $recipe_id
 * @property string|null $user_id
 * @property string $change created, edited, installed, refreshed or duplicated
 * @property string $name
 * @property string|null $description
 * @property string $script encrypted
 * @property CarbonImmutable $created_at
 * @property-read Recipe $recipe
 * @property-read User|null $user
 */
#[Hidden(['script'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class RecipeRevision extends Model
{
    /**
     * Revisions are written once, so they only record when.
     *
     * @var string|null
     */
    public const UPDATED_AT = null;

    /**
     * Get the recipe this is a version of.
     *
     * @return BelongsTo<Recipe, $this>
     */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /**
     * Get who saved it.
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
     * Encrypts the script and reads `created_at` as an immutable date.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['script' => 'encrypted', 'created_at' => 'immutable_datetime'];
    }
}
