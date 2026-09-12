<?php

namespace App\Data;

class EnterpriseSsoCallbackData
{
    /** Carry the validated authorization code and state from an enterprise SSO callback. */
    public function __construct(
        public readonly string $code,
        public readonly string $state,
    ) {}
}
