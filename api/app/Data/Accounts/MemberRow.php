<?php

declare(strict_types=1);

namespace App\Data\Accounts;

use App\Enums\AccountRole;
use Carbon\CarbonImmutable;

final readonly class MemberRow
{
    /**
     * Create a new MemberRow instance.
     *
     * One member on the members page.
     *
     * @param  string  $membershipId  The membership's ID, used by the change-role and remove forms.
     * @param  string  $name  The member's name.
     * @param  string  $email  The member's email.
     * @param  AccountRole  $role  Their role in the account.
     * @param  ?CarbonImmutable  $joinedAt  When they joined; null for memberships created before this was recorded.
     * @param  bool  $isYou  Whether this row is the viewer.
     * @param  bool  $manageable  Whether the viewer may change this member's role or remove them.
     * @param  list<string>|null  $serviceAccess  null means every service
     * @param  bool  $canLimitServices  Owners and admins always have every service, so only other roles can be limited.
     * @param  list<string>|null  $projectAccess  the projects they can see; null means every project
     * @param  bool  $deployProtected  whether they may deploy to protected environments
     * @param  bool  $secondFactor  whether they sign in with an authenticator app or a passkey as well as a password
     */
    public function __construct(
        public string $membershipId,
        public string $name,
        public string $email,
        public AccountRole $role,
        public ?CarbonImmutable $joinedAt,
        public bool $isYou,
        public bool $manageable,
        public ?array $serviceAccess = null,
        public bool $canLimitServices = false,
        public ?array $projectAccess = null,
        public bool $deployProtected = false,
        public bool $secondFactor = false,
    ) {}
}
