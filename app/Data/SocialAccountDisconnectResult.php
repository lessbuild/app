<?php

namespace App\Data;

class SocialAccountDisconnectResult
{
    public const DISCONNECTED = 'disconnected';

    public const LAST_METHOD = 'last_method';

    public const MISSING = 'missing';

    /**
     * Carry the locked social-account outcome and provider label to the HTTP response boundary.
     *
     * @param  'disconnected'|'last_method'|'missing'  $status  The operation outcome.
     */
    public function __construct(
        public readonly string $status,
        public readonly string $providerName,
    ) {}
}
