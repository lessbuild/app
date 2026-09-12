<?php

namespace App\Services;

use App\Models\StatusSubscription;

class StatusSubscriptionTokenVerifier
{
    /** Return whether a pending confirmation token matches the stored digest. */
    public function matchesVerificationToken(StatusSubscription $subscription, string $token): bool
    {
        return $subscription->verification_token_hash !== null
            && hash_equals($subscription->verification_token_hash, hash('sha256', $token));
    }

    /** Return whether an unsubscribe token matches the encrypted subscription token. */
    public function matchesUnsubscribeToken(StatusSubscription $subscription, string $token): bool
    {
        return hash_equals($subscription->unsubscribe_token, $token);
    }
}
