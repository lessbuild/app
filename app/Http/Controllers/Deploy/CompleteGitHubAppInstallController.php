<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\InstallGitHubApp;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** GitHub's "Setup URL" after installing the App (`/github-app/callback`, as Deployer registered it). */
final class CompleteGitHubAppInstallController
{
    public function __invoke(Request $request, #[CurrentUser] User $user, #[CurrentAccount] Account $account, InstallGitHubApp $install): RedirectResponse
    {
        $data = $request->validate(['installation_id' => ['required', 'integer', 'min:1'], 'setup_action' => ['nullable', 'in:install,update'], 'state' => ['required', 'string', 'size:64']]);
        $provider = $install->handle($user, $account, (string) $data['installation_id'], $data['state'], $request->session()->pull('github_app_installation_state'));

        return to_route('github-app.repositories', $provider->id)->with('status', __('GitHub App installed. Connect one of its repositories from a project’s Deploy section.'));
    }
}
