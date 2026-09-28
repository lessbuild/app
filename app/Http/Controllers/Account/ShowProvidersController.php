<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Enums\ProviderType;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\User;
use App\Queries\Infrastructure\ProvidersQuery;
use App\Services\Deploy\GitHubApp;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

/** The account's provider credentials: clouds for servers, Cloudflare for DNS, Git hosts for Deploy. */
final class ShowProvidersController
{
    /**
     * Show the providers page, with the GitHub App option when it's configured.
     *
     * @param  Account  $account
     * @param  User  $user
     * @param  ProvidersQuery  $providers
     * @param  GitHubApp  $github
     * @return View
     */
    public function __invoke(#[CurrentAccount] Account $account, #[CurrentUser] User $user, ProvidersQuery $providers, GitHubApp $github): View
    {
        return view('account.providers', ['account' => $account, 'providers' => $providers->handle($account->id), 'types' => ProviderType::cases(), 'githubApp' => $github->configured()]);
    }
}
