<?php

declare(strict_types=1);

namespace App\Data\Users;

final readonly class UpdateProfileData
{
    /**
     * Create a new UpdateProfileData instance.
     *
     * A change to the signed-in person's profile.
     *
     * @param  string  $name  Their new name.
     * @param  string  $email  Their new email. Changing it asks them to verify it again.
     */
    public function __construct(
        public string $name,
        public string $email,
    ) {}
}
