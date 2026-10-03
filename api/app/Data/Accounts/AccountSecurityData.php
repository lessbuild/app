<?php

declare(strict_types=1);

namespace App\Data\Accounts;

/** The security rules and single sign-on settings an owner is saving. */
final readonly class AccountSecurityData
{
    /**
     * Create a new AccountSecurityData instance.
     *
     * @param  bool  $requireTwoFactor  Members must use two-factor authentication or a passkey.
     * @param  list<string>  $emailDomains  Lowercase domains people may be invited from; empty allows any.
     * @param  list<string>  $ipRanges  Addresses or CIDR ranges the account can be used from; empty allows any.
     * @param  int|null  $idleMinutes  Sign people out after this long without activity; null never does.
     * @param  string|null  $ssoIssuer  The identity provider's issuer URL.
     * @param  string|null  $ssoClientId
     * @param  string|null  $ssoClientSecret  A new secret, or null to keep the saved one.
     * @param  bool  $ssoEnforced  Members must sign in through the identity provider.
     */
    public function __construct(
        public bool $requireTwoFactor,
        public array $emailDomains,
        public array $ipRanges,
        public ?int $idleMinutes,
        public ?string $ssoIssuer,
        public ?string $ssoClientId,
        public ?string $ssoClientSecret,
        public bool $ssoEnforced,
    ) {}
}
