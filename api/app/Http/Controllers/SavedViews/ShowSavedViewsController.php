<?php

declare(strict_types=1);

namespace App\Http\Controllers\SavedViews;

use App\Models\SavedView;
use App\Models\User;
use App\Support\SavedViewPages;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `GET /api/app/saved-views?page=audit-log&project=`. */
final class ShowSavedViewsController
{
    /**
     * Return the person's saved views of one page (in one project, for project pages), each with its address.
     *
     * @param  Request  $request
     * @param  User  $user
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $page = $request->string('page')->toString();
        if (! SavedViewPages::exists($page)) {
            return response()->json(['views' => []]);
        }
        $project = $request->string('project')->toString();

        return response()->json(['views' => SavedView::query()->where('user_id', $user->id)->where('page', $page)
            ->where(fn ($query) => $query->whereNull('account_id')->orWhere('account_id', $user->current_account_id))->orderBy('name')->get()
            ->filter(fn (SavedView $view): bool => $project === '' || ($view->parameters['project'] ?? null) === $project)
            ->map(fn (SavedView $view): array => ['id' => $view->id, 'name' => $view->name, 'url' => SavedViewPages::url($view->page, $view->parameters, $view->query)])
            ->values()]);
    }
}
