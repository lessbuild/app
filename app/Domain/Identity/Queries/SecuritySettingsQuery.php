<?php

declare(strict_types=1);

namespace App\Domain\Identity\Queries;

use App\Domain\Identity\Data\PasskeySummary;
use App\Domain\Identity\Data\SecuritySettings;
use App\Domain\Identity\Enums\TwoFactorState;
use App\Domain\Identity\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Passkey;

final class SecuritySettingsQuery
{
    public function handle(User $user, bool $revealRecoveryCodes = false): SecuritySettings
    {
        $state = match (true) {
            $user->hasEnabledTwoFactorAuthentication() => TwoFactorState::On,
            $user->two_factor_secret !== null => TwoFactorState::Pending,
            default => TwoFactorState::Off,
        };

        $recoveryCodes = [];
        if ($state === TwoFactorState::On && $revealRecoveryCodes) {
            $recoveryCodes = array_values(array_filter((array) $user->recoveryCodes(), is_string(...)));
        }

        $passkeys = array_values($user->passkeys()->latest()->get()->map(fn (Passkey $passkey): PasskeySummary => new PasskeySummary(
            id: (int) $passkey->getKey(),
            name: (string) $passkey->name,
            authenticator: $passkey->authenticator,
            createdAt: $passkey->created_at ? CarbonImmutable::instance($passkey->created_at) : null,
            lastUsedAt: $passkey->last_used_at ? CarbonImmutable::instance($passkey->last_used_at) : null,
        ))->all());

        return new SecuritySettings(
            hasPassword: $user->password !== null,
            twoFactor: $state,
            pendingSecret: $state === TwoFactorState::Pending ? (string) Fortify::currentEncrypter()->decrypt($user->two_factor_secret) : null,
            pendingQrCodeSvg: $state === TwoFactorState::Pending ? $user->twoFactorQrCodeSvg() : null,
            recoveryCodes: $recoveryCodes,
            passkeys: $passkeys,
        );
    }
}
