<?php

declare(strict_types=1);

namespace App\Data\Accounts;

use App\Enums\AccountRole;
use Carbon\CarbonImmutable;

final readonly class MemberRow
{
    public function __construct(
        public string $membershipId,
        public string $name,
        public string $email,
        public AccountRole $role,
        public ?CarbonImmutable $joinedAt,
        public bool $isYou,
        /** Whether the viewer may change this member's role or remove them. */
        public bool $manageable,
        /** @var list<string>|null null means every service */
        public ?array $serviceAccess = null,
        /** Owners and admins always have every service, so only other roles can be limited. */
        public bool $canLimitServices = false,
    ) {}
}
