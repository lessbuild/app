<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Queries;

use App\Domain\Accounts\Models\Account;
use App\Domain\Accounts\Models\Membership;
use App\Platform\Search\Like;
use App\Platform\Search\SearchResult;

final class SearchMembersQuery
{
    /** @return list<SearchResult> members whose name or email matches */
    public function handle(Account $account, string $term, int $limit = 6): array
    {
        $pattern = Like::contains($term);

        return array_values(Membership::query()
            ->where('account_id', $account->id)
            ->whereHas('user', fn ($query) => $query->whereRaw("lower(name) like ? escape '\\'", [$pattern])->orWhereRaw("lower(email) like ? escape '\\'", [$pattern]))
            ->with('user')
            ->limit($limit)
            ->get()
            ->map(fn (Membership $membership): SearchResult => new SearchResult($membership->user->name, route('account.members'), $membership->user->email.' · '.$membership->role->label(), __('Member')))
            ->all());
    }
}
