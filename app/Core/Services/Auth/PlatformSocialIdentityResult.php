<?php

namespace App\Core\Services\Auth;

final class PlatformSocialIdentityResult
{
    public const CONNECTED = 'connected';

    public const ALREADY_CONNECTED = 'already_connected';

    public const PROVIDER_ALREADY_CONNECTED = 'provider_already_connected';

    public const OWNED_BY_ANOTHER_ACCOUNT = 'owned_by_another_account';

    public const DISCONNECTED = 'disconnected';

    public const LAST_SIGN_IN_METHOD = 'last_sign_in_method';

    public const MISSING = 'missing';

    public function __construct(public readonly string $status) {}
}
