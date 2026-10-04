<?php

declare(strict_types=1);

namespace App\Http\Controllers\Search;

use App\Models\User;
use App\Platform\Search\SearchResult;
use App\Queries\Accounts\SearchMembersQuery;
use App\Queries\Projects\SearchProjectsQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Search behind the command palette: {groups: [{label, results: [{title, url, subtitle, type}]}]}, within the current account. */
final class SearchController
{
    /**
     * Search for the command palette: projects, domains and members of the current account matching at least two typed
     * characters, grouped, with empty groups left out.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  SearchProjectsQuery  $projects
     * @param  SearchMembersQuery  $members
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, SearchProjectsQuery $projects, SearchMembersQuery $members): JsonResponse
    {
        $term = mb_substr(trim($request->string('q')->toString()), 0, 100);
        $account = $user->currentAccount;
        if ($account === null || mb_strlen($term) < 2 || ! $user->can('view', $account)) {
            return response()->json(['groups' => []]);
        }

        $groups = [
            ['label' => __('Projects'), 'results' => $projects->projects($account, $term, 6, $user)],
            ['label' => __('Domains'), 'results' => $projects->domains($account, $term, 6, $user)],
            ['label' => __('Members'), 'results' => $members->handle($account, $term)],
        ];

        return response()->json(['groups' => array_values(array_filter(array_map(
            fn (array $group): array => ['label' => $group['label'], 'results' => array_map(fn (SearchResult $result): array => (array) $result, $group['results'])],
            $groups,
        ), fn (array $group): bool => $group['results'] !== []))]);
    }
}
