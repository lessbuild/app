<?php

namespace App\Modules\Deployer\Http\Controllers;

use App\Modules\Deployer\Actions\Provider\StoreGitHubAppPrivateKeyAction;
use App\Modules\Deployer\Http\Requests\StoreGitHubAppPrivateKeyRequest;
use App\Modules\Deployer\Services\GitHubApp;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class GitHubAppSetupController extends Controller
{
    /**
     * Render the local-only setup page while keeping the private key outside the public filesystem and response.
     */
    public function create(GitHubApp $github): View
    {
        $this->authorizeSetup($github);

        return view('admin.github-app-setup', [
            'appIdConfigured' => filled(config('github-app.id')),
            'slugConfigured' => filled(config('github-app.slug')),
            'webhookSecretConfigured' => filled(config('github-app.webhook_secret')),
        ]);
    }

    /** Store the validated key and send the administrator back to provider setup. */
    public function store(StoreGitHubAppPrivateKeyRequest $request, StoreGitHubAppPrivateKeyAction $store, GitHubApp $github): RedirectResponse
    {
        $this->authorizeSetup($github);
        $store->handle($request->privateKey()->get());

        return redirect()->route('providers.index')->with('success', __('GitHub App private key installed. You can now install the GitHub App.'));
    }

    private function authorizeSetup(GitHubApp $github): void
    {
        abort_unless(app()->environment(['local', 'testing']) && (bool) config('github-app.setup_enabled'), 404);
        $this->authorize('platform-admin');
        abort_if($github->hasPrivateKey(), 404);
    }
}
