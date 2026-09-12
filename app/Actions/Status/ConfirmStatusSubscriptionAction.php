<?php

namespace App\Actions\Status;

use App\Models\StatusSubscription;
use App\Services\StatusSubscriptionTokenVerifier;

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
