<?php

namespace App\Http\Controllers;

use App\Models\Provider;
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
        abort_unless($request->user()->currentOrganization->permits($request->user(), 'manage'), 403);
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
    public function callback(Request $request, GitHubApp $github): RedirectResponse
    {
        abort_unless($request->user()->currentOrganization->permits($request->user(), 'manage'), 403);
        $data = $request->validate([
            'installation_id' => ['required', 'integer', 'min:1'],
            'setup_action' => ['nullable', 'string', 'in:install,update'],
            'state' => ['required', 'string', 'size:64'],
        ]);
        $expected = $request->session()->pull('github_app_installation_state');
        abort_unless(is_string($expected) && hash_equals($expected, hash('sha256', $data['state'])), 403);
        $repositories = $github->repositories($data['installation_id']);
        $account = str($repositories[0]['full_name'] ?? 'GitHub')->before('/')->toString();
        $provider = $request->user()->workspaceProviders()->updateOrCreate([
            'provider' => Provider::TYPE_GITHUB,
            'credential_type' => 'app',
            'external_id' => (string) $data['installation_id'],
        ], [
            'user_id' => $request->user()->id,
            'name' => __('GitHub App · :account', ['account' => $account]),
            'description' => __('Repositories installed through the BuildPusher GitHub App.'),
            'token' => 'github-app-installation',
            'connection_status' => Provider::CONNECTION_HEALTHY,
            'connection_checked_at' => now(),
        ]);

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
