<?php

namespace App\Core\Services\Auth;

use App\Core\Models\PlatformUser;
use App\Core\Models\UserIdentity;
use Illuminate\Support\Facades\DB;

/** Connects one identity per provider to a locked Core account. */
final class ConnectPlatformSocialIdentity
{
    public function handle(
        PlatformUser $actor,
        string $provider,
        string $providerUserId,
        string $providerEmail,
    ): PlatformSocialIdentityResult {
        if (! in_array($provider, PlatformSocialProviders::keys(), true)
            || trim($providerUserId) === ''
            || strlen($providerUserId) > 191
            || filter_var($providerEmail, FILTER_VALIDATE_EMAIL) === false) {
            return new PlatformSocialIdentityResult(PlatformSocialIdentityResult::MISSING);
        }

        return DB::connection('core')->transaction(function () use ($actor, $provider, $providerUserId, $providerEmail): PlatformSocialIdentityResult {
            $user = PlatformUser::query()->lockForUpdate()->findOrFail($actor->getKey());
            abort_unless($user->status === 'active', 403);
            $existingProvider = UserIdentity::query()
                ->where('user_id', $user->getKey())
                ->where('provider', $provider)
                ->lockForUpdate()
                ->first();

            if ($existingProvider !== null) {
                if ($existingProvider->provider_user_id !== $providerUserId) {
                    return new PlatformSocialIdentityResult(PlatformSocialIdentityResult::PROVIDER_ALREADY_CONNECTED);
                }

                if ($existingProvider->status === 'active') {
                    return new PlatformSocialIdentityResult(PlatformSocialIdentityResult::ALREADY_CONNECTED);
                }

                $existingProvider->forceFill([
                    'provider_email' => $providerEmail,
                    'verified_at' => now(),
                    'status' => 'active',
                ])->save();

                return new PlatformSocialIdentityResult(PlatformSocialIdentityResult::CONNECTED);
            }

            $owner = UserIdentity::query()
                ->where('provider', $provider)
                ->where('provider_user_id', $providerUserId)
                ->lockForUpdate()
                ->first();

            if ($owner !== null) {
                return new PlatformSocialIdentityResult(PlatformSocialIdentityResult::OWNED_BY_ANOTHER_ACCOUNT);
            }

            UserIdentity::query()->create([
                'user_id' => $user->getKey(),
                'provider' => $provider,
                'provider_user_id' => $providerUserId,
                'provider_email' => $providerEmail,
                'verified_at' => now(),
                'status' => 'active',
            ]);

            if (! filled($user->auth_type)) {
                $user->forceFill(['auth_type' => $provider])->save();
            }

            return new PlatformSocialIdentityResult(PlatformSocialIdentityResult::CONNECTED);
        }, attempts: 3);
    }
}
