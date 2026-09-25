<?php

namespace App\Core\Services\Auth;

use App\Core\Models\PlatformUser;
use App\Core\Models\UserIdentity;
use Illuminate\Support\Facades\DB;

/** Removes an owned identity only when Core retains another usable sign-in method. */
final class DisconnectPlatformSocialIdentity
{
    public function handle(PlatformUser $actor, string $provider): PlatformSocialIdentityResult
    {
        if (! in_array($provider, PlatformSocialProviders::keys(), true)) {
            return new PlatformSocialIdentityResult(PlatformSocialIdentityResult::MISSING);
        }

        return DB::connection('core')->transaction(function () use ($actor, $provider): PlatformSocialIdentityResult {
            $user = PlatformUser::query()->lockForUpdate()->findOrFail($actor->getKey());
            $identity = UserIdentity::query()
                ->where('user_id', $user->getKey())
                ->where('provider', $provider)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if ($identity === null) {
                return new PlatformSocialIdentityResult(PlatformSocialIdentityResult::MISSING);
            }

            $hasOtherIdentity = UserIdentity::query()
                ->where('user_id', $user->getKey())
                ->where('status', 'active')
                ->where('id', '!=', $identity->getKey())
                ->exists();
            $hasOtherMethod = $user->hasPassword() || $user->passkeys()->exists() || $hasOtherIdentity;

            if (! $hasOtherMethod) {
                return new PlatformSocialIdentityResult(PlatformSocialIdentityResult::LAST_SIGN_IN_METHOD);
            }

            $identity->delete();

            if ($user->auth_type === $provider) {
                $otherProvider = UserIdentity::query()
                    ->where('user_id', $user->getKey())
                    ->where('status', 'active')
                    ->value('provider');
                $user->forceFill([
                    'auth_type' => $otherProvider
                        ?: ($user->hasPassword() ? 'password' : ($user->passkeys()->exists() ? 'passkey' : null)),
                ])->save();
            }

            return new PlatformSocialIdentityResult(PlatformSocialIdentityResult::DISCONNECTED);
        }, attempts: 3);
    }
}
