<?php

declare(strict_types=1);

namespace App\Actions\SavedViews;

use App\Models\SavedView;
use App\Models\User;
use App\Support\SavedViewPages;
use Illuminate\Validation\ValidationException;

final class SaveView
{
    /**
     * Save a person's filters on a page under a name, replacing a view of the same name, up to SavedView::LIMIT a page.
     *
     * @param  User  $user
     * @param  string  $page  one of SavedViewPages::PAGES
     * @param  string  $name
     * @param  array<array-key, mixed>  $parameters  the page's route parameters
     * @param  array<array-key, mixed>  $query  the page's filters
     * @return SavedView
     */
    public function handle(User $user, string $page, string $name, array $parameters, array $query): SavedView
    {
        $parameters = SavedViewPages::parameters($page, $parameters);
        if (! SavedViewPages::exists($page) || count($parameters) !== count(SavedViewPages::PAGES[$page]['parameters'])) {
            throw ValidationException::withMessages(['saved_view_name' => __('This page’s filters can’t be saved.')]);
        }
        $scope = SavedView::query()->where('user_id', $user->id)->where('page', $page)->where('account_id', $user->current_account_id);
        $view = (clone $scope)->where('name', $name)->first() ?? new SavedView;
        if (! $view->exists && (clone $scope)->count() >= SavedView::LIMIT) {
            throw ValidationException::withMessages(['saved_view_name' => __('You can save up to :limit views here. Delete one first.', ['limit' => SavedView::LIMIT])]);
        }
        $view->forceFill(['user_id' => $user->id, 'account_id' => $user->current_account_id, 'page' => $page, 'name' => $name, 'parameters' => $parameters, 'query' => SavedViewPages::query($page, $query)])->save();

        return $view;
    }
}
