<?php

namespace App\Modules\Deployer\Actions\Provider;

use App\Modules\Deployer\Models\Provider;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\Core\DeployerProjectAccess;
use App\Modules\Deployer\Services\GitHubApp;
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

        $identity = [
            'provider' => Provider::TYPE_GITHUB,
            'credential_type' => 'app',
            'external_id' => $installationId,
        ];
        // Inspect the actual existing connection before scoped upsert can treat it as absent.
        $existing = $actor->currentOrganization->providers()->where($identity)->first();
        abort_unless($existing === null || app(DeployerProjectAccess::class)->canChangeProvider($actor, $existing), 403);

        $repositories = $this->github->repositories($installationId);
        $account = str($repositories[0]['full_name'] ?? 'GitHub')->before('/')->toString();

        return $actor->workspaceProviders()->updateOrCreate($identity, [
            'user_id' => $actor->id,
            'name' => __('GitHub App · :account', ['account' => $account]),
            'description' => __('Repositories installed through the :app GitHub App.', ['app' => config('app.name')]),
            'token' => 'github-app-installation',
            'connection_status' => Provider::CONNECTION_HEALTHY,
            'connection_checked_at' => now(),
        ]);
    }
}
