<?php

namespace App\Modules\Deployer\Actions\Status;

use App\Modules\Deployer\Models\StatusSubscription;
use App\Modules\Deployer\Services\StatusSubscriptionTokenVerifier;

class ConfirmStatusSubscriptionAction
{
    public function __construct(
        private readonly StatusSubscriptionTokenVerifier $tokens,
    ) {}

    /**
     * Verify the one-time confirmation token and enable the subscription.
     *
     * @return bool Whether the token matched and the subscription was updated.
     */
    public function handle(StatusSubscription $subscription, string $token): bool
    {
        if (! $this->tokens->matchesVerificationToken($subscription, $token)) {
            return false;
        }

        $subscription->update(['verified_at' => now(), 'verification_token_hash' => null]);

        return true;
    }
}
