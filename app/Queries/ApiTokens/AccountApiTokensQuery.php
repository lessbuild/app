<?php

declare(strict_types=1);

namespace App\Queries\ApiTokens;

use App\Data\ApiTokens\ApiTokenRow;
use App\Enums\ApiScope;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

final class AccountApiTokensQuery
{
    /**
     * The account's API tokens, newest first, with their owners' names ("Former member" once the owner is gone) and
     * scopes.
     *
     * @return list<ApiTokenRow>
     */
    public function handle(Account $account, User $viewer): array
    {
        $tokens = ApiToken::query()->where('account_id', $account->id)->latest()->latest('id')->get();
        $owners = User::query()->whereIn('id', $tokens->pluck('tokenable_id')->unique())->pluck('name', 'id');
        $immutable = fn (?Carbon $at): ?CarbonImmutable => $at !== null ? CarbonImmutable::instance($at) : null;

        return array_values($tokens->map(fn (ApiToken $token): ApiTokenRow => new ApiTokenRow(
            id: $token->id,
            name: $token->name,
            owner: (string) ($owners[$token->tokenable_id] ?? __('Former member')),
            ownedByViewer: $token->tokenable_id === $viewer->id,
            scopes: array_values(array_filter(array_map(ApiScope::tryFrom(...), $token->abilities))),
            lastUsedAt: $immutable($token->last_used_at),
            expiresAt: $immutable($token->expires_at),
            createdAt: $immutable($token->created_at),
        ))->all());
    }
}
