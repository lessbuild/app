<?php

declare(strict_types=1);

namespace App\Data\Users;

final readonly class UpdateProfileData
{
    public function __construct(
        public string $name,
        public string $email,
    ) {}
}
