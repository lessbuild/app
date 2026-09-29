<?php

declare(strict_types=1);

namespace App\Data\Users;

use SensitiveParameter;

final readonly class RegisterUserData
{
    /**
     * Create a new RegisterUserData instance.
     *
     * The details a new person registers with.
     *
     * @param  string  $name  Their name.
     * @param  string  $email  Their email, which becomes their sign-in.
     * @param  ?string  $password  Their chosen password; null when they register through a provider.
     * @param  ?string  $accessInvite  The access invitation token they came with, while registration is closed.
     * @param  ?string  $referralCode  The refer-a-friend code they signed up with, if any.
     */
    public function __construct(
        public string $name,
        public string $email,
        #[SensitiveParameter] public ?string $password,
        #[SensitiveParameter] public ?string $accessInvite = null,
        public ?string $referralCode = null,
    ) {}
}
