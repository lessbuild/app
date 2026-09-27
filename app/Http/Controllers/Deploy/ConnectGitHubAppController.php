<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Services\Deploy\GitHubApp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Sends an admin to GitHub to install the App, remembering a one-time state for the way back. */
final class ConnectGitHubAppController
{
    /**
     * Redirects to GitHub's install page with a fresh state, whose hash waits in the session for the callback. 503 when
     * the App isn't configured.
     */
    public function __invoke(Request $request, GitHubApp $github): RedirectResponse
    {
        abort_unless($github->configured(), 503, 'The GitHub App isn’t configured yet.');
        $state = Str::random(64);
        $request->session()->put('github_app_installation_state', hash('sha256', $state));

        return redirect()->away($github->installationUrl($state));
    }
}
