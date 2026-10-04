<?php

declare(strict_types=1);

namespace App\Http\Controllers\SavedViews;

use App\Actions\SavedViews\SaveView;
use App\Models\User;
use App\Support\SavedViewPages;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class StoreSavedViewController
{
    /**
     * Save the filters a page is showing under a name, then open the saved view.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  SaveView  $save
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, SaveView $save): JsonResponse
    {
        $data = $request->validate([
            'saved_view_page' => ['required', 'string', Rule::in(array_keys(SavedViewPages::PAGES))],
            'saved_view_name' => ['required', 'string', 'max:60'],
            'parameters' => ['array'],
            'query' => ['array'],
        ], attributes: ['saved_view_name' => __('name')]);
        $view = $save->handle($user, (string) $data['saved_view_page'], trim((string) $data['saved_view_name']), (array) ($data['parameters'] ?? []), (array) ($data['query'] ?? []));

        return response()->json(['id' => $view->id, 'url' => SavedViewPages::url($view->page, $view->parameters, $view->query), 'message' => __('Saved “:name”.', ['name' => $view->name])], 201);
    }
}
