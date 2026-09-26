<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

enum TwoFactorState: string
{
    case Off = 'off';
    /** A secret exists but the user has not yet confirmed a code from their authenticator. */
    case Pending = 'pending';
    case On = 'on';
}
