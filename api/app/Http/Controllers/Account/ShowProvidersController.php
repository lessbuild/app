<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Data\Infrastructure\ProviderSummary;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\Provider;
use App\Queries\Infrastructure\ProvidersQuery;
use App\Services\Deploy\GitHubApp;
use Illuminate\Http\JsonResponse;

/** `GET /api/app/account/providers`. */
final class ShowProvidersController
{
    /**
     * Return the account's providers (never their tokens) with their connection status, and the types one can be.
     *
     * @param  Account  $account
     * @param  ProvidersQuery  $providers
     * @param  GitHubApp  $github
     * @return JsonResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, ProvidersQuery $providers, GitHubApp $github): JsonResponse
    {
        return response()->json([
            'account' => ['id' => $account->id, 'name' => $account->name],
            'providers' => $providers->handle($account->id)->map(fn (Provider $provider): ProviderSummary => ProviderSummary::from($provider))->values(),
            'types' => ProviderSummary::types(),
            // Installing the GitHub App is offered only when the app's credentials are set.
            'githubApp' => $github->configured(),
        ]);
    }
}
