<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Enums\ProviderType;
use App\Models\Account;
use App\Models\Provider;
use App\Models\User;
use App\Services\Deploy\GitHubApp;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

final class InstallGitHubApp
{
    /**
     * Create a new InstallGitHubApp instance.
     *
     * Connects an installation of the GitHub App to the account.
     *
     * @param  GitHubApp  $github  Lists the repositories the installation can reach.
     */
    public function __construct(private readonly GitHubApp $github) {}

    /**
     * Record the installation GitHub sent the person back with as a GitHub provider of the account. `$state` must
     * match the hash kept in their session when they left, so an installation can't be attached by a link.
     *
     * @param  User  $actor
     * @param  Account  $account
     * @param  string  $installationId
     * @param  string  $state
     * @param  string|null  $expectedHash
     * @return Provider
     */
    public function handle(User $actor, Account $account, string $installationId, string $state, ?string $expectedHash): Provider
    {
        Gate::forUser($actor)->authorize('create', Provider::class);
        if (! is_string($expectedHash) || ! hash_equals($expectedHash, hash('sha256', $state))) {
            throw new AuthorizationException(__('That GitHub App link has expired. Start the installation again.'));
        }
        $repositories = $this->github->repositories($installationId);
        $owner = str($repositories[0]['full_name'] ?? 'GitHub')->before('/')->toString();
        $provider = Provider::query()->where('account_id', $account->id)->where('type', ProviderType::GitHub)->where('credential_type', 'app')->where('external_id', $installationId)->first() ?? new Provider;
        $provider->forceFill([
            'account_id' => $account->id, 'created_by' => $provider->created_by ?? $actor->id, 'type' => ProviderType::GitHub, 'credential_type' => 'app',
            'external_id' => $installationId, 'name' => __('GitHub App · :owner', ['owner' => $owner]), 'token' => 'github-app-installation',
            'connection_status' => 'healthy', 'connection_checked_at' => now(),
        ])->save();

        return $provider;
    }
}
