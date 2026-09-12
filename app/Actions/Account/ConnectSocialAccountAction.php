<?php

namespace App\Actions\Account;

use App\Data\SocialAccountConnectionResult;
use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Database\DatabaseManager;

class ConnectSocialAccountAction
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ActivityRecorder $activity,
    ) {}

    /** Attach a verified provider identity under the existing account lock unless another user owns it. */
    public function handle(User $actor, string $provider, string $providerId): SocialAccountConnectionResult
    {
        $providerColumn = User::SOCIAL_PROVIDER_COLUMNS[$provider];
        $result = $this->database->transaction(function () use ($actor, $providerColumn, $providerId): string {
            $user = User::query()->lockForUpdate()->findOrFail($actor->getKey());
            $owner = User::query()->where($providerColumn, $providerId)->first();

            if ($owner && ! $owner->is($user)) {
                return SocialAccountConnectionResult::OWNED;
            }

            $user->forceFill([$providerColumn => $providerId])->save();

            return SocialAccountConnectionResult::CONNECTED;
        });

        if ($result === SocialAccountConnectionResult::CONNECTED) {
            $this->activity->recordAccount($actor, ucfirst($provider).' sign-in was connected.');
        }

        return new SocialAccountConnectionResult($result);
    }
}
