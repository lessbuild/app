<?php

namespace App\Services;

use App\Models\Recipe;

class RecipePublication
{
    /**
     * Prepare persisted recipe attributes while preserving publication timestamps and gallery revision identity.
     *
     * @param  array<string, mixed>  $attributes  Validated recipe content and publication settings.
     * @param  Recipe|null  $recipe  Existing recipe for an update, or null for creation.
     * @return array<string, mixed> Attributes ready for recipe persistence.
     */
    public function attributes(array $attributes, ?Recipe $recipe = null): array
    {
        $published = (bool) $attributes['is_published'];
        $attributes['category'] = $published ? ($attributes['category'] ?? null) : null;
        if (! $published) {
            $attributes['published_at'] = null;
            $attributes['gallery_revision_at'] = null;

            return $attributes;
        }

        $newPublication = ! $recipe?->is_published || $recipe->published_at === null;
        $contentChanged = $recipe === null
            || $recipe->name !== $attributes['name']
            || $recipe->description !== ($attributes['description'] ?? null)
            || $recipe->script !== $attributes['script']
            || $recipe->category !== $attributes['category'];

        $attributes['published_at'] = $newPublication ? now() : $recipe->published_at;
        $attributes['gallery_revision_at'] = $newPublication || $contentChanged
            ? now()
            : ($recipe->gallery_revision_at ?? $recipe->published_at ?? now());

        return $attributes;
    }
}
