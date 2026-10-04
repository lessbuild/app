<?php

declare(strict_types=1);

namespace App\Http\Controllers\SavedViews;

use App\Models\SavedView;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class DeleteSavedViewController
{
    /**
     * Delete one of the person's own saved views and go back.
     *
     * @param  User  $user
     * @param  string  $view
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, string $view): JsonResponse
    {
        SavedView::query()->where('user_id', $user->id)->findOrFail((int) $view)->delete();

        return response()->json(['message' => __('Saved view deleted.')]);
    }
}
