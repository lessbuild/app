<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Data\RegisterUserData;
use App\Domain\Identity\Data\SocialProfile;
use App\Domain\Identity\Data\SocialSignInResult;
use App\Domain\Identity\Enums\SocialProvider;
use App\Domain\Identity\Enums\SocialSignInOutcome;
use App\Domain\Identity\Events\SocialIdentityConnected;
use App\Domain\Identity\Models\SocialIdentity;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SignInWithSocialProfile
{
    public function __construct(private readonly RegisterUser $registerUser) {}

    /**
     * Find the user behind a provider identity, or register a new one. An existing account is never
     * matched by email alone; its owner has to sign in and connect the provider from their settings.
     */
    public function handle(SocialProvider $provider, SocialProfile $profile, bool $registrationOpen): SocialSignInResult
    {
        $identity = SocialIdentity::query()
            ->where('provider', $provider)
            ->where('provider_user_id', $profile->providerUserId)
            ->first();

        if ($identity !== null) {
            $identity->forceFill(['last_used_at' => now(), 'email' => $profile->verifiedEmail ?? $identity->email])->save();

            return new SocialSignInResult(SocialSignInOutcome::SignedIn, $identity->user);
        }

        if ($profile->verifiedEmail === null) {
            return new SocialSignInResult(SocialSignInOutcome::NoVerifiedEmail);
        }
        $email = Str::lower(trim($profile->verifiedEmail));
        if (User::query()->where('email', $email)->exists()) {
            return new SocialSignInResult(SocialSignInOutcome::EmailInUse);
        }
        if (! $registrationOpen) {
            return new SocialSignInResult(SocialSignInOutcome::RegistrationClosed);
        }

        $user = DB::transaction(function () use ($provider, $profile, $email): User {
            $user = $this->registerUser->handle(new RegisterUserData($profile->name, $email, null));
            // The provider has verified this address, so there is nothing more to confirm.
            $user->forceFill(['email_verified_at' => now()])->save();

            $identity = new SocialIdentity;
            $identity->forceFill([
                'user_id' => $user->id,
                'provider' => $provider,
                'provider_user_id' => $profile->providerUserId,
                'email' => $email,
                'last_used_at' => now(),
            ])->save();

            return $user;
        });

        SocialIdentityConnected::dispatch($user, $provider);

        return new SocialSignInResult(SocialSignInOutcome::Registered, $user);
    }
}
