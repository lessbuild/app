<?php

namespace App\Data;

class SocialAccountConnectionResult
{
    public const CONNECTED = 'connected';

    public const OWNED = 'owned';

    /** Carry the named result of an authenticated provider-connection attempt. */
    public function __construct(public readonly string $status) {}
}
