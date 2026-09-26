<?php

namespace App\Core\Data\Auth;

use App\Core\Models\PlatformAuthSession;
use App\Core\Models\PlatformUser;

final readonly class PlatformSsoExchange
{
    public function __construct(
        public PlatformUser $user,
        public PlatformAuthSession $authSession,
        public string $returnUrl,
    ) {}
}
