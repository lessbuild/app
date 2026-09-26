<?php

declare(strict_types=1);

namespace App\Domain\Identity\Data;

use SensitiveParameter;

final readonly class RegisterUserData
{
    public function __construct(
        public string $name,
        public string $email,
        #[SensitiveParameter] public ?string $password,
    ) {}
}
