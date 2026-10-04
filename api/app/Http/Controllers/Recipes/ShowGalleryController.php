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
use Illuminate\Http\Request;

final class ShowGalleryController
{
    /**
     * List the recipes other accounts published (`?q=`, `?category=`, `?sort=popular|rating|newest`, `?favorites=1`,
     * `?page=`).
     *
     * @param  Request  $request
     * @param  Account  $account
     * @param  User  $user
     * @param  RecipeGalleryQuery  $gallery
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentAccount] Account $account, #[CurrentUser] User $user, RecipeGalleryQuery $gallery): JsonResponse
    {
        $filters = ['q' => $request->string('q')->limit(100, '')->toString(), 'category' => $request->string('category')->toString(), 'sort' => $request->string('sort')->toString(), 'favorites' => $request->boolean('favorites')];
        $recipes = $gallery->handle($user, $filters);

        return response()->json([
            'account' => ['id' => $account->id, 'name' => $account->name],
            'recipes' => collect($recipes->items())->map(fn (Recipe $recipe): array => [
                'id' => $recipe->id,
                'name' => $recipe->name,
                'category' => $recipe->category->label(),
                'publisher' => $recipe->account->name,
                'description' => $recipe->description,
                'installs' => (int) $recipe->install_count,
                'ratings' => (int) $recipe->getAttribute('ratings_count'),
                'average' => $recipe->getAttribute('ratings_avg_rating') === null ? null : round((float) $recipe->getAttribute('ratings_avg_rating'), 1),
                'installed' => (bool) $recipe->getAttribute('installed'),
                'favorited' => (bool) $recipe->getAttribute('favorited'),
            ])->values(),
            'page' => $recipes->currentPage(),
            'lastPage' => $recipes->lastPage(),
            'filters' => $filters,
            'categories' => RecipeChoices::categories(),
            'canSeeReports' => $user->can('update', $account),
        ]);
    }
}
