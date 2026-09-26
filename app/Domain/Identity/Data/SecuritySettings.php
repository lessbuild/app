<?php

declare(strict_types=1);

namespace App\Domain\Identity\Data;

use App\Domain\Identity\Enums\TwoFactorState;

final readonly class SecuritySettings
{
    /**
     * @param  list<string>  $recoveryCodes  only filled right after codes are created, so they are shown once
     * @param  list<PasskeySummary>  $passkeys
     */
    public function __construct(
        public bool $hasPassword,
        public TwoFactorState $twoFactor,
        public ?string $pendingSecret,
        public ?string $pendingQrCodeSvg,
        public array $recoveryCodes,
        public array $passkeys,
    ) {}
}
