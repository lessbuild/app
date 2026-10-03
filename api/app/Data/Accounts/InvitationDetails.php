<?php

declare(strict_types=1);

namespace App\Data\Accounts;

final readonly class InvitationDetails
{
    /**
     * Create a new InvitationDetails instance.
     *
     * A pending invitation to join an account, as the invitation page shows it.
     *
     * @param  string  $accountName
     * @param  string  $role  The role's name, such as Member.
     * @param  string  $email  The address it was sent to.
     * @param  string|null  $invitedBy  Who sent it.
     * @param  string  $expiresAt  ISO 8601.
     */
    public function __construct(
        public string $accountName,
        public string $role,
        public string $email,
        public ?string $invitedBy,
        public string $expiresAt,
    ) {}
}
