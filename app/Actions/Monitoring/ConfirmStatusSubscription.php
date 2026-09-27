<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Models\StatusSubscription;
use Carbon\CarbonImmutable;

final class ConfirmStatusSubscription
{
    /** Confirm a subscription with the one-time token from its email. Returns false when the token doesn't match or was already used. */
    public function handle(StatusSubscription $subscription, string $token): bool
    {
        if ($subscription->verification_token_hash === null || ! hash_equals($subscription->verification_token_hash, hash('sha256', $token))) {
            return false;
        }
        $subscription->forceFill(['verified_at' => CarbonImmutable::now('UTC'), 'verification_token_hash' => null])->save();

        return true;
    }
}
