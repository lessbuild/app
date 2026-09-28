<?php

declare(strict_types=1);

namespace App\Data\Users;

use App\Enums\SocialSignInOutcome;
use App\Models\User;

final readonly class SocialSignInResult
{
    /**
     * Create a new SocialSignInResult instance.
     *
     * What happened when someone came back from a provider's sign-in page.
     *
     * @param  SocialSignInOutcome  $outcome  Signed in, registered, or the reason neither happened.
     * @param  ?User  $user  The person signed in or registered; null when the outcome is a refusal.
     */
    public function __construct(
        public SocialSignInOutcome $outcome,
        public ?User $user = null,
    ) {}
}
