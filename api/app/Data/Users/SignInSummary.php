<?php

declare(strict_types=1);

namespace App\Data\Users;

use App\Enums\SignInMethod;
use Carbon\CarbonImmutable;

final readonly class SignInSummary
{
    /**
     * Create a new SignInSummary instance.
     *
     * One sign-in attempt on the sign-in activity list.
     *
     * @param  bool  $succeeded  Whether the attempt signed the person in.
     * @param  ?SignInMethod  $method  How they signed in (password, passkey, a provider…), when known.
     * @param  bool  $twoFactor  Whether a two-factor code was part of the attempt.
     * @param  string  $device  A readable browser and system.
     * @param  ?string  $ipAddress  Where the attempt came from.
     * @param  CarbonImmutable  $at  When it happened.
     */
    public function __construct(
        public bool $succeeded,
        public ?SignInMethod $method,
        public bool $twoFactor,
        public string $device,
        public ?string $ipAddress,
        public CarbonImmutable $at,
    ) {}
}
