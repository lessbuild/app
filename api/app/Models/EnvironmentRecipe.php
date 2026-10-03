<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A snapshot of a library recipe kept on an environment, run in order with the others on the environment's servers.
 * Library edits don't change it until it's refreshed.
 *
 * @property int $id
 * @property string $environment_id
 * @property int|null $recipe_id the library recipe it came from; null once that's deleted
 * @property int $position
 * @property string $name
 * @property string $script encrypted
 * @property CarbonImmutable|null $source_updated_at when the library recipe was last changed at snapshot time
 * @property string|null $added_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Environment $environment
 * @property-read Recipe|null $recipe
 */
#[Hidden(['script'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class EnvironmentRecipe extends Model
{
    /**
     * Get the environment it belongs to.
     *
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * Get the library recipe it was taken from.
     *
     * @return BelongsTo<Recipe, $this>
     */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /**
     * Determine whether the library recipe has changed since this snapshot was taken.
     *
     * @return bool
     */
    public function isBehind(): bool
    {
        $changed = $this->recipe?->updated_at;

        return $changed !== null && ($this->source_updated_at === null || $changed->gt($this->source_updated_at));
    }

    /**
     * Take the library recipe's current name and script.
     *
     * @param  Recipe  $recipe
     * @return void
     */
    public function snapshot(Recipe $recipe): void
    {
        $this->forceFill(['recipe_id' => $recipe->id, 'name' => $recipe->name, 'script' => $recipe->script, 'source_updated_at' => $recipe->updated_at]);
    }

    /**
     * Get the attributes that should be cast.
     *
     * Encrypts the script and reads the positions and snapshot time.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['script' => 'encrypted', 'position' => 'integer', 'source_updated_at' => 'immutable_datetime'];
    }
}
