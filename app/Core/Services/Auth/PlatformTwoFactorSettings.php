<?php

namespace App\Core\Services\Auth;

use App\Core\Models\PlatformUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Performs account-security transitions against a locked Core identity record. */
final class PlatformTwoFactorSettings
{
    public function __construct(private readonly PlatformTwoFactorCredentials $credentials) {}

    public function begin(PlatformUser $user): void
    {
        DB::connection('core')->transaction(function () use ($user): void {
            $lockedUser = PlatformUser::query()->lockForUpdate()->findOrFail($user->getKey());
            abort_unless($lockedUser->status === 'active', 403);
            if ($lockedUser->twoFactorEnabled()) {
                throw $this->invalidState(__('Two-factor authentication is already enabled.'));
            }

            $lockedUser->forceFill([
                'two_factor_secret' => $this->credentials->encryptSecret($this->credentials->generateSecret()),
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();
        });
    }

    public function pendingSecret(PlatformUser $user): ?string
    {
        if ($user->twoFactorEnabled()) {
            return null;
        }

        return $this->credentials->decryptSecret($user->two_factor_secret);
    }

    public function cancelPendingSetup(PlatformUser $user): bool
    {
        return DB::connection('core')->transaction(function () use ($user): bool {
            $lockedUser = PlatformUser::query()->lockForUpdate()->findOrFail($user->getKey());
            abort_unless($lockedUser->status === 'active', 403);
            if ($lockedUser->twoFactorEnabled() || $this->credentials->decryptSecret($lockedUser->two_factor_secret) === null) {
                return false;
            }

            $lockedUser->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();

            return true;
        });
    }

    /** @return list<string> */
    public function confirm(PlatformUser $user, string $code): array
    {
        return DB::connection('core')->transaction(function () use ($user, $code): array {
            $lockedUser = PlatformUser::query()->lockForUpdate()->findOrFail($user->getKey());
            abort_unless($lockedUser->status === 'active', 403);
            $secret = $this->credentials->decryptSecret($lockedUser->two_factor_secret);

            if ($lockedUser->twoFactorEnabled() || $secret === null || ! $this->credentials->validTotp($secret, $code)) {
                throw $this->invalidCode();
            }

            $recoveryCodes = $this->credentials->generateRecoveryCodes();
            $lockedUser->forceFill([
                'two_factor_recovery_codes' => $this->credentials->encryptRecoveryHashes(
                    $this->credentials->hashRecoveryCodes($recoveryCodes),
                ),
                'two_factor_confirmed_at' => now(),
            ])->save();

            return $recoveryCodes;
        });
    }

    public function disable(PlatformUser $user, string $code): void
    {
        DB::connection('core')->transaction(function () use ($user, $code): void {
            $lockedUser = PlatformUser::query()->lockForUpdate()->findOrFail($user->getKey());
            abort_unless($lockedUser->status === 'active', 403);
            if (! $lockedUser->twoFactorEnabled()) {
                throw $this->invalidState(__('Two-factor authentication is not enabled.'));
            }

            $recoveryHashes = $this->credentials->decryptRecoveryHashes($lockedUser->two_factor_recovery_codes);
            if (! $this->credentials->matches((string) $lockedUser->two_factor_secret, $recoveryHashes, $code)) {
                throw $this->invalidCode();
            }

            $lockedUser->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();
        });
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(PlatformUser $user, string $code): array
    {
        return DB::connection('core')->transaction(function () use ($user, $code): array {
            $lockedUser = PlatformUser::query()->lockForUpdate()->findOrFail($user->getKey());
            abort_unless($lockedUser->status === 'active', 403);
            if (! $lockedUser->twoFactorEnabled()) {
                throw $this->invalidState(__('Enable two-factor authentication before regenerating recovery codes.'));
            }

            $recoveryHashes = $this->credentials->decryptRecoveryHashes($lockedUser->two_factor_recovery_codes);
            if (! $this->credentials->matches((string) $lockedUser->two_factor_secret, $recoveryHashes, $code)) {
                throw $this->invalidCode();
            }

            $recoveryCodes = $this->credentials->generateRecoveryCodes();
            $lockedUser->forceFill([
                'two_factor_recovery_codes' => $this->credentials->encryptRecoveryHashes(
                    $this->credentials->hashRecoveryCodes($recoveryCodes),
                ),
            ])->save();

            return $recoveryCodes;
        });
    }

    private function invalidCode(): ValidationException
    {
        return ValidationException::withMessages([
            'code' => __('The authentication or recovery code is invalid.'),
        ])->errorBag('twoFactor');
    }

    private function invalidState(string $message): ValidationException
    {
        return ValidationException::withMessages([
            'code' => $message,
        ])->errorBag('twoFactor');
    }
}
