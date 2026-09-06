<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Scopes\RecipeScopes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Recipe extends Model
{
    use BelongsToOrganization;
    use HasFactory;
    use RecipeScopes;

    public const CATEGORIES = [
        'security',
        'runtime',
        'database',
        'monitoring',
        'deployment',
        'utilities',
    ];

    protected $hidden = ['script'];

    protected $casts = [
        'script' => 'encrypted',
        'is_published' => 'boolean',
        'published_at' => 'datetime',
        'gallery_revision_at' => 'datetime',
        'source_revision_at' => 'datetime',
        'install_count' => 'integer',
    ];

    protected $fillable = [
        'name',
        'description',
        'script',
        'is_published',
        'category',
        'published_at',
        'gallery_revision_at',
        'source_revision_at',
        'install_count',
        'source_recipe_id',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsToMany<Server, $this> */
    public function servers(): BelongsToMany
    {
        return $this->belongsToMany(Server::class)
            ->withPivot('position')
            ->orderByPivot('position');
    }

    /** @return BelongsTo<Recipe, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_recipe_id');
    }

    /** @return HasMany<Recipe, $this> */
    public function installs(): HasMany
    {
        return $this->hasMany(self::class, 'source_recipe_id');
    }

    /** @return HasMany<RecipeRating, $this> */
    public function ratings(): HasMany
    {
        return $this->hasMany(RecipeRating::class);
    }

    /** @return HasMany<RecipeFavorite, $this> */
    public function favorites(): HasMany
    {
        return $this->hasMany(RecipeFavorite::class);
    }

    /** @return HasMany<RecipeReport, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(RecipeReport::class);
    }

    /** @return MorphMany<Event, $this> */
    public function events(): MorphMany
    {
        return $this->morphMany(Event::class, 'parentable');
    }

    /**
     * Compare the installed snapshot revision with its gallery source.
     *
     * @param  ?self  $source  The source to compare; defaults to the related gallery recipe.
     * @return bool Whether the source has a newer recorded gallery revision.
     */
    public function hasGalleryUpdate(?self $source = null): bool
    {
        $source ??= $this->source;

        return $source !== null
            && $source->gallery_revision_at !== null
            && ($this->source_revision_at === null
                || $source->gallery_revision_at->isAfter($this->source_revision_at));
    }
}
