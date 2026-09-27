<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\Provider;
use App\Services\Deploy\GitHubApp;
use Illuminate\Contracts\View\View;

/** The repositories a GitHub App installation can reach, with a link to connect each in a project. */
final class ShowGitHubAppRepositoriesController
{
    public function __invoke(#[CurrentAccount] Account $account, Provider $provider, GitHubApp $github): View
    {
        abort_unless($provider->isGitHubApp(), 404);

        return view('deploy.github-app-repositories', [
            'account' => $account, 'provider' => $provider, 'repositories' => $github->repositories((string) $provider->external_id),
            'projects' => $account->projects()->orderBy('name')->get(),
        ]);
    }
}
