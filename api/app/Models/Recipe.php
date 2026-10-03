<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RecipeCategory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An account's Bash script that runs as root at the end of a new server's provisioning. Owners and admins can publish
 * it to the gallery, where other accounts install copies of it.
 *
 * @property int $id
 * @property string $account_id
 * @property string|null $created_by
 * @property string $name
 * @property string|null $description
 * @property RecipeCategory $category
 * @property string $script encrypted
 * @property bool $is_published shared in the gallery with every account
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $gallery_revision_at when the published script last changed; copies compare against it
 * @property int|null $source_recipe_id the gallery recipe this is an installed copy of
 * @property CarbonImmutable|null $source_revision_at the source's gallery revision this copy has
 * @property int $install_count how many accounts installed it from the gallery
 * @property int|null $legacy_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Account $account
 * @property-read User|null $creator
 * @property-read Recipe|null $source
 * @property-read \Illuminate\Database\Eloquent\Collection<int, RecipeRevision> $revisions
 * @property-read \Illuminate\Database\Eloquent\Collection<int, RecipeRating> $ratings
 * @property-read \Illuminate\Database\Eloquent\Collection<int, RecipeReport> $reports
 */
#[Hidden(['script'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class Recipe extends Model
{
    /**
     * The longest script accepted, in bytes.
     *
     * @var int
     */
    public const MAX_SCRIPT_BYTES = 65535;

    /**
     * How many revisions each recipe keeps.
     *
     * @var int
     */
    public const KEEP_REVISIONS = 50;

    /**
     * Get the account the recipe belongs to.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Get who created it.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the gallery recipe this is a copy of.
     *
     * @return BelongsTo<Recipe, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_recipe_id');
    }

    /**
     * Get the installed copies of this recipe in other accounts.
     *
     * @return HasMany<Recipe, $this>
     */
    public function copies(): HasMany
    {
        return $this->hasMany(self::class, 'source_recipe_id');
    }

    /**
     * Get the recipe's saved versions, newest first.
     *
     * @return HasMany<RecipeRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(RecipeRevision::class)->latest('id');
    }

    /**
     * Get the gallery ratings.
     *
     * @return HasMany<RecipeRating, $this>
     */
    public function ratings(): HasMany
    {
        return $this->hasMany(RecipeRating::class);
    }

    /**
     * Get who marked it a favourite.
     *
     * @return HasMany<RecipeFavorite, $this>
     */
    public function favorites(): HasMany
    {
        return $this->hasMany(RecipeFavorite::class);
    }

    /**
     * Get the reports filed against it.
     *
     * @return HasMany<RecipeReport, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(RecipeReport::class);
    }

    /**
     * Limit a query to recipes in the gallery.
     *
     * @param  Builder<Recipe>  $query
     * @return void
     */
    #[\Illuminate\Database\Eloquent\Attributes\Scope]
    protected function published(Builder $query): void
    {
        $query->where('is_published', true);
    }

    /**
     * Determine whether this copy's gallery source has published a newer revision than the copy has.
     *
     * @return bool
     */
    public function hasGalleryUpdate(): bool
    {
        $source = $this->source;

        return $source !== null && $source->is_published && $source->gallery_revision_at !== null
            && ($this->source_revision_at === null || $source->gallery_revision_at->gt($this->source_revision_at));
    }

    /**
     * Get the attributes that should be cast.
     *
     * Encrypts the script and reads the category, flags and timestamps.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => RecipeCategory::class, 'script' => 'encrypted', 'is_published' => 'boolean', 'published_at' => 'immutable_datetime',
            'gallery_revision_at' => 'immutable_datetime', 'source_revision_at' => 'immutable_datetime', 'install_count' => 'integer',
        ];
    }
}
