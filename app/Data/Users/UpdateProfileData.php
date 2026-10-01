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
     * @param  string|null  $locale  The language they want the app in; null follows their browser.
     */
    public function __construct(
        public string $name,
        public string $email,
        public ?string $locale = null,
    ) {}
}
