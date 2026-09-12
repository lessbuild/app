<?php

namespace App\Actions\Status;

use App\Models\StatusSubscription;
use App\Services\StatusSubscriptionTokenVerifier;

class UnsubscribeStatusSubscriptionAction
{
    public function __construct(
        private readonly StatusSubscriptionTokenVerifier $tokens,
    ) {}

    /**
     * Verify the unsubscribe token, remove the subscription and return its page slug.
     *
     * @return string|null The status-page slug when the token matched, otherwise null.
     */
    public function handle(StatusSubscription $subscription, string $token): ?string
    {
        if (! $this->tokens->matchesUnsubscribeToken($subscription, $token)) {
            return null;
        }

        $slug = $subscription->statusPage->slug;
        $subscription->delete();

        return $slug;
    }
}
