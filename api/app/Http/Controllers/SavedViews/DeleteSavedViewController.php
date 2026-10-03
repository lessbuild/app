<?php

declare(strict_types=1);

namespace App\Http\Controllers\SavedViews;

use App\Models\SavedView;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DeleteSavedViewController
{
    /**
     * Delete one of the person's own saved views and go back.
     *
     * @param  User  $user
     * @param  string  $view
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, string $view): RedirectResponse
    {
        SavedView::query()->where('user_id', $user->id)->findOrFail((int) $view)->delete();

        return back()->with('status', __('Saved view deleted.'));
    }
}
