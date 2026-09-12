<?php

namespace App\Actions\Account;

use App\Data\SocialAccountDisconnectResult;
use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Database\DatabaseManager;

class DisconnectSocialAccountAction
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ActivityRecorder $activity,
    ) {}

    /**
     * Disconnect a provider under an account lock while retaining a usable sign-in method.
     *
     * @param  User  $actor  The authenticated account whose provider is being disconnected.
     * @param  string  $provider  A route-constrained social provider key.
     */
    public function handle(User $actor, string $provider): SocialAccountDisconnectResult
    {
        $providerName = $this->providerName($provider);
        $status = $this->database->transaction(function () use ($actor, $provider): string {
            $user = User::query()->lockForUpdate()->findOrFail($actor->getKey());
            $column = User::SOCIAL_PROVIDER_COLUMNS[$provider];
            $connected = $user->connectedSocialProviders();

            if (! in_array($provider, $connected, true)) {
                return SocialAccountDisconnectResult::MISSING;
            }

            if (! $user->hasLocalPassword() && count($connected) === 1) {
                return SocialAccountDisconnectResult::LAST_METHOD;
            }

            $remaining = array_values(array_diff($connected, [$provider]));
            $user->forceFill([
                $column => null,
                'auth_type' => $user->auth_type === $provider
                    ? ($remaining[0] ?? null)
                    : $user->auth_type,
            ])->save();

            return SocialAccountDisconnectResult::DISCONNECTED;
        });

        if ($status === SocialAccountDisconnectResult::DISCONNECTED) {
            $this->activity->recordAccount($actor, $providerName.' sign-in was disconnected.');
        }

        return new SocialAccountDisconnectResult($status, $providerName);
    }

    /** Return the existing human-readable provider label used in responses and account activity. */
    private function providerName(string $provider): string
    {
        return match ($provider) {
            'github' => 'GitHub',
            'gitlab' => 'GitLab',
            'bitbucket' => 'Bitbucket',
        };
    }
}
