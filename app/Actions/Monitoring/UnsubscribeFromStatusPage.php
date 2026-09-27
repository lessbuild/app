<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Models\StatusSubscription;

final class UnsubscribeFromStatusPage
{
    /** Remove a subscription with the token from any update email. Returns false when the token doesn't match. */
    public function handle(StatusSubscription $subscription, string $token): bool
    {
        if (! hash_equals($subscription->unsubscribe_token, $token)) {
            return false;
        }
        $subscription->delete();

        return true;
    }
}
