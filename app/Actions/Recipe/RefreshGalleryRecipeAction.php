<?php

namespace App\Actions\Recipe;

use App\Models\Recipe;
use App\Services\ActivityRecorder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class RefreshGalleryRecipeAction
{
    public function __construct(private readonly ActivityRecorder $activity) {}

    /**
     * Refresh an unpublished private copy from its published source under deterministic copy/source locks.
     *
     * @param  Recipe  $copy  Private installed recipe being refreshed.
     * @return bool Whether the copy was refreshed; false when it is already published.
     *
     * @throws ModelNotFoundException When the copy or source is unavailable.
     */
    public function handle(Recipe $copy): bool
    {
        $refreshed = DB::transaction(function () use ($copy): bool {
            $lockedCopy = Recipe::query()->lockForUpdate()->findOrFail($copy->id);
            if ($lockedCopy->is_published) {
                return false;
            }

            $source = Recipe::query()
                ->published()
                ->lockForUpdate()
                ->findOrFail($lockedCopy->source_recipe_id);

            $lockedCopy->update([
                'name' => $source->name,
                'description' => $source->description,
                'script' => $source->script,
                'source_revision_at' => $source->gallery_revision_at,
            ]);

            return true;
        });

        if (! $refreshed) {
            return false;
        }

        $copy->refresh();
        $this->activity->record(
            $copy,
            $copy->user_id,
            'recipe',
            "Private gallery recipe \"{$copy->name}\" was refreshed.",
        );

        return true;
    }
}
