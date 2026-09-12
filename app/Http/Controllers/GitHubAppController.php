<?php

namespace App\Http\Controllers;

use App\Actions\Provider\InstallGitHubAppAction;
use App\Http\Requests\GitHubAppCallbackRequest;
use App\Models\Organization;
use App\Models\Provider;
use App\Models\User;
use App\Services\GitHubApp;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GitHubAppController extends Controller
{
    /**
     * Require workspace management and configured GitHub App credentials, store installation state, and redirect to GitHub.
     */
    public function connect(Request $request, GitHubApp $github): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var Organization $organization */
        $organization = $user->currentOrganization;
        $this->authorize('manage', $organization);
        abort_unless($github->configured(), 503, 'GitHub App installation is not configured yet.');
        $state = Str::random(64);
        $request->session()->put('github_app_installation_state', hash('sha256', $state));

        return redirect()->away($github->installationUrl($state));
    }

    /**
     * Validate installation details and one-time session state for a workspace manager, then attach the installation provider.
     *
     * @return RedirectResponse The installation's repository picker after remote repository access succeeds.
     */
    public function callback(GitHubAppCallbackRequest $request, InstallGitHubAppAction $install): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $provider = $install->handle($user, $request->installationId(), $request->state());

        return redirect()->route('github-app.repositories', $provider)->with('success', __('GitHub App installed. Choose a repository to connect.'));
    }

    /**
     * Require a current-workspace GitHub App provider and render its remotely accessible repositories; foreign providers return 404.
     */
    public function repositories(Request $request, Provider $provider, GitHubApp $github): View
    {
        abort_unless($provider->organization_id === $request->user()->current_organization_id && $provider->isGitHubApp(), 404);

        return view('scenes.repositories.github-app', [
            'provider' => $provider,
            'repositories' => $github->repositories($provider->external_id),
        ]);
    }
}
