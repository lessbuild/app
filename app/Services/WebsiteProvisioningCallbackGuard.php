<?php

namespace App\Services;

use App\Models\Website;

class WebsiteProvisioningCallbackGuard
{
    /**
     * Check whether the supplied attempt token matches the current website token.
     *
     * @param  Website  $website  The freshly locked website row.
     * @param  mixed  $attempt  Raw callback attempt token.
     * @return bool Whether this callback belongs to the current attempt.
     */
    public function matchesAttempt(Website $website, mixed $attempt): bool
    {
        return ! $website->provisioning_token
            || hash_equals($website->provisioning_token, (string) $attempt);
    }

    /**
     * Check attempt identity and the mutable provisioning states accepted by status/failure callbacks.
     *
     * @param  Website  $website  The freshly locked website row.
     * @param  mixed  $attempt  Raw callback attempt token.
     * @return bool Whether this callback can still change provisioning state.
     */
    public function acceptsLifecycle(Website $website, mixed $attempt): bool
    {
        return $this->matchesAttempt($website, $attempt)
            && in_array($website->provisioning_status, [
                Website::STATUS_QUEUED,
                Website::STATUS_PROVISIONING,
            ], true);
    }
}
