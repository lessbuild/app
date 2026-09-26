<?php

namespace App\Core\Services\Auth;

use App\Core\Models\PlatformUser;
use Illuminate\Support\Facades\DB;

/** Verifies imported TOTP secrets and consumes the hashed recovery codes used by the source apps. */
final class VerifyPlatformTwoFactorCode
{
    public function __construct(private readonly PlatformTwoFactorCredentials $credentials) {}

    public function handle(PlatformUser $user, string $submittedCode): bool
    {
        if (! $user->twoFactorEnabled()) {
            return false;
        }

        $secret = $this->credentials->decryptSecret($user->two_factor_secret);
        if ($secret !== null && $this->credentials->validTotp($secret, $submittedCode)) {
            return true;
        }

        $recoveryHash = $this->credentials->recoveryCodeHash($submittedCode);
        if ($recoveryHash === $this->credentials->recoveryCodeHash('')) {
            return false;
        }

        return DB::connection('core')->transaction(function () use ($user, $recoveryHash): bool {
            $lockedUser = PlatformUser::query()->lockForUpdate()->find($user->getKey());
            if (! $lockedUser || ! $lockedUser->twoFactorEnabled()) {
                return false;
            }

            $recoveryHashes = $this->credentials->decryptRecoveryHashes($lockedUser->two_factor_recovery_codes);
            $matches = collect($recoveryHashes)->contains(fn (string $hash): bool => hash_equals($hash, $recoveryHash));
            if (! $matches) {
                return false;
            }

            $lockedUser->forceFill([
                'two_factor_recovery_codes' => $this->credentials->encryptRecoveryHashes(
                    array_values(array_filter($recoveryHashes, fn (string $hash): bool => ! hash_equals($hash, $recoveryHash))),
                ),
            ])->save();

            return true;
        });
    }
}
