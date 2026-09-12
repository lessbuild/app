<?php

namespace App\Actions\Provider;

use App\Models\Provider;
use App\Models\User;
use App\Services\GitHubApp;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Session\Session;

class InstallGitHubAppAction
{
    public function __construct(
        private readonly GitHubApp $github,
        private readonly Session $session,
    ) {}

    /**
     * Consume the one-time installation state, discover repositories, and upsert the workspace app provider.
     *
     * The remote discovery remains outside any database transaction. The state is consumed before the remote
     * request, preserving one-time callback semantics when discovery fails or a callback is replayed.
     *
     * @throws AuthorizationException If the callback state is absent, malformed or already consumed.
     */
    public function handle(User $actor, string $installationId, string $state): Provider
    {
        $expected = $this->session->pull('github_app_installation_state');
        if (! is_string($expected) || ! hash_equals($expected, hash('sha256', $state))) {
            throw new AuthorizationException;
        }

        $repositories = $this->github->repositories($installationId);
        $account = str($repositories[0]['full_name'] ?? 'GitHub')->before('/')->toString();

        return $actor->workspaceProviders()->updateOrCreate([
            'provider' => Provider::TYPE_GITHUB,
            'credential_type' => 'app',
            'external_id' => $installationId,
        ], [
            'user_id' => $actor->id,
            'name' => __('GitHub App · :account', ['account' => $account]),
            'description' => __('Repositories installed through the BuildPusher GitHub App.'),
            'token' => 'github-app-installation',
            'connection_status' => Provider::CONNECTION_HEALTHY,
            'connection_checked_at' => now(),
        ]);
    }
}
