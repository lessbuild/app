<?php

declare(strict_types=1);

namespace App\Data\Users;

use App\Enums\TwoFactorState;

final readonly class SecuritySettings
{
    /**
     * Everything the security settings page shows about the signed-in person's sign-in methods.
     *
     * @param  bool  $hasPassword  False for people who only ever signed in with a provider or passkey; the page offers
     *                             to set one.
     * @param  TwoFactorState  $twoFactor  Whether two-factor authentication is off, waiting for its first code, or on.
     * @param  ?string  $pendingSecret  The TOTP secret to type into an authenticator app, while setup is pending.
     * @param  ?string  $pendingQrCodeSvg  The same secret as a QR code, while setup is pending.
     * @param  list<string>  $recoveryCodes  only filled right after codes are created, so they are shown once
     * @param  list<PasskeySummary>  $passkeys
     * @param  list<SocialIdentitySummary>  $socialIdentities
     */
    public function __construct(
        public bool $hasPassword,
        public TwoFactorState $twoFactor,
        public ?string $pendingSecret,
        public ?string $pendingQrCodeSvg,
        public array $recoveryCodes,
        public array $passkeys,
        public array $socialIdentities,
    ) {}
}
