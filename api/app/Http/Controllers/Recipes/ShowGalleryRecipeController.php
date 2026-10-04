<?php

declare(strict_types=1);

namespace App\Http\Controllers\Recipes;

use App\Data\Recipes\RecipeChoices;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\Recipe;
use App\Models\User;
use App\Queries\Recipes\RecipeGalleryQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowGalleryRecipeController
{
    /**
     * Show a gallery recipe: its script, ratings and installs, and the person's favourite, rating, report and installed
     * copy.
     *
     * @param  Account  $account
     * @param  User  $user
     * @param  string  $recipe
     * @param  RecipeGalleryQuery  $gallery
     * @return JsonResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, #[CurrentUser] User $user, string $recipe, RecipeGalleryQuery $gallery): JsonResponse
    {
        $data = $gallery->recipe($user, $recipe);
        $published = $data['recipe'];
        $report = $data['report'];
        $copy = $data['copy'];

        return response()->json([
            'account' => ['id' => $account->id, 'name' => $account->name],
            'recipe' => [
                'id' => $published->id,
                'name' => $published->name,
                'category' => $published->category->label(),
                'publisher' => $published->account->name,
                'description' => $published->description,
                'script' => $published->script,
                'installs' => (int) $published->install_count,
                'ratings' => (int) $published->getAttribute('ratings_count'),
                'average' => $published->getAttribute('ratings_avg_rating') === null ? null : round((float) $published->getAttribute('ratings_avg_rating'), 1),
                'updatedAt' => $published->gallery_revision_at?->toIso8601String(),
            ],
            'favorited' => $data['favorited'],
            'rating' => $data['rating']?->rating,
            'report' => $report === null ? null : ['reason' => $report->reason->value, 'details' => $report->details, 'status' => $report->status, 'note' => $report->resolution_note],
            'copy' => $copy === null ? null : ['id' => $copy->id, 'updateAvailable' => $copy->hasGalleryUpdate()],
            'reasons' => RecipeChoices::reasons(),
            'canInstall' => $user->can('create', [Recipe::class, $account]),
            'canRate' => $user->can('rate', $published),
            'canReport' => $user->can('report', $published),
            'canSeeReports' => $user->can('update', $account),
        ]);
    }
}
