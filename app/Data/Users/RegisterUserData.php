<?php

declare(strict_types=1);

namespace App\Data\Users;

use SensitiveParameter;

final readonly class RegisterUserData
{
    /**
     * The details a new person registers with.
     *
     * @param  string  $name  Their name.
     * @param  string  $email  Their email, which becomes their sign-in.
     * @param  ?string  $password  Their chosen password; null when they register through a provider.
     */
    public function __construct(
        public string $name,
        public string $email,
        #[SensitiveParameter] public ?string $password,
    ) {}
}
