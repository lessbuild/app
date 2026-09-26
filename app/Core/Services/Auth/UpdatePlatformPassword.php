<?php

namespace App\Core\Services\Auth;

use App\Core\Models\PlatformAuthSession;
use App\Core\Models\PlatformUser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class UpdatePlatformPassword
{
    public function __construct(
        private readonly Hasher $hasher,
        private readonly VerifyPlatformTwoFactorCode $twoFactorCode,
    ) {}

    public function handle(
        PlatformUser $user,
        string $password,
        string $currentSessionId,
        ?string $currentPassword,
        ?string $code,
    ): void {
        DB::connection('core')->transaction(function () use ($user, $password, $currentSessionId, $currentPassword, $code): void {
            $lockedUser = PlatformUser::query()->lockForUpdate()->findOrFail($user->getKey());
            abort_unless($lockedUser->status === 'active', 403);
            $currentSession = PlatformAuthSession::query()
                ->whereKey($currentSessionId)
                ->where('user_id', $lockedUser->getKey())
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->first();

            if ($currentSession === null) {
                throw new AuthorizationException('The current authentication session is no longer active.');
            }

            if ($lockedUser->hasPassword() && ! $this->hasher->check($currentPassword ?? '', (string) $lockedUser->password)) {
                throw ValidationException::withMessages([
                    'current_password' => __('The current password is incorrect.'),
                ])->errorBag('password');
            }

            if (! $lockedUser->hasPassword() && $lockedUser->twoFactorEnabled()
                && ! $this->twoFactorCode->handle($lockedUser, $code ?? '')) {
                throw ValidationException::withMessages([
                    'code' => __('The authentication or recovery code is invalid.'),
                ])->errorBag('password');
            }

            $rememberToken = Str::random(60);
            $lockedUser->forceFill([
                'password' => $this->hasher->make($password),
                'password_set_at' => now(),
                'remember_token' => $rememberToken,
            ])->save();
            $user->setRememberToken($rememberToken);

            $currentSession->forceFill([
                'remembered' => false,
                'remember_token_hash' => null,
            ])->save();

            PlatformAuthSession::query()
                ->where('user_id', $lockedUser->getKey())
                ->whereNull('revoked_at')
                ->where('id', '!=', $currentSession->getKey())
                ->update(['revoked_at' => now(), 'updated_at' => now()]);
        });
    }
}
