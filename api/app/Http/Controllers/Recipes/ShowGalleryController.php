<?php

declare(strict_types=1);

namespace App\Http\Controllers\Recipes;

use App\Enums\RecipeCategory;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\User;
use App\Queries\Recipes\RecipeGalleryQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class ShowGalleryController
{
    /**
     * Show the gallery of published recipes, searched, filtered and sorted from the query string.
     *
     * @param  Request  $request
     * @param  Account  $account
     * @param  User  $user
     * @param  RecipeGalleryQuery  $gallery
     * @return View
     */
    public function __invoke(Request $request, #[CurrentAccount] Account $account, #[CurrentUser] User $user, RecipeGalleryQuery $gallery): View
    {
        $filters = ['q' => $request->string('q')->limit(100, '')->toString(), 'category' => $request->string('category')->toString(), 'sort' => $request->string('sort')->toString(), 'favorites' => $request->boolean('favorites')];

        return view('recipes.gallery', ['account' => $account, 'recipes' => $gallery->handle($user, $filters), 'filters' => $filters, 'categories' => RecipeCategory::cases()]);
    }
}
