<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

enum SocialSignInOutcome
{
    case SignedIn;
    case Registered;
    /** The provider gave no verified email address, so we can't create or match an account. */
    case NoVerifiedEmail;
    /** An account already uses the email. Matching on email alone would let a provider account take it over. */
    case EmailInUse;
    case RegistrationClosed;
}
