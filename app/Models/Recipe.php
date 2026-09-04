<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recipe extends Model
{
    use HasFactory;

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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function servers(): BelongsToMany
    {
        return $this->belongsToMany(Server::class)
            ->withPivot('position')
            ->orderByPivot('position');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_recipe_id');
    }

    public function installs(): HasMany
    {
        return $this->hasMany(self::class, 'source_recipe_id');
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(RecipeRating::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true)->whereNotNull('published_at');
    }

    public function scopeInUse(Builder $query): Builder
    {
        return $query->whereHas('servers');
    }

    public function scopeUnused(Builder $query): Builder
    {
        return $query->whereDoesntHave('servers');
    }

    public function hasGalleryUpdate(?self $source = null): bool
    {
        $source ??= $this->source;

        return $source !== null
            && $source->gallery_revision_at !== null
            && ($this->source_revision_at === null
                || $source->gallery_revision_at->isAfter($this->source_revision_at));
    }
}
