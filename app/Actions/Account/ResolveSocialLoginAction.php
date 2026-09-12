<?php

namespace App\Actions\Account;

use App\Data\SocialIdentityData;
use App\Data\SocialLoginResolution;
use App\Models\User;
use App\Services\PersonalOrganization;
use App\Services\RegistrationAccess;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Str;

class ResolveSocialLoginAction
{
    public function __construct(
        private readonly RegistrationAccess $registration,
        private readonly PersonalOrganization $organizations,
        private readonly Hasher $hasher,
    ) {}

    /** Resolve an existing social account or create a new one under the registration lock. */
    public function handle(SocialIdentityData $identity): SocialLoginResolution
    {
        $resolution = $this->registration->synchronized(function () use ($identity): SocialLoginResolution {
            $providerColumn = User::SOCIAL_PROVIDER_COLUMNS[$identity->provider];
            $user = User::query()->where($providerColumn, $identity->providerId)->first();

            if ($user) {
                return new SocialLoginResolution(SocialLoginResolution::RESOLVED, $user);
            }

            if (User::query()->whereRaw('LOWER(email) = ?', [$identity->email])->exists()) {
                return new SocialLoginResolution(SocialLoginResolution::EXISTING_EMAIL);
            }

            if (! $this->registration->allowsNewUser()) {
                return new SocialLoginResolution(SocialLoginResolution::CLOSED);
            }

            return new SocialLoginResolution(
                SocialLoginResolution::RESOLVED,
                User::create([
                    'name' => $identity->name,
                    'email' => $identity->email,
                    $providerColumn => $identity->providerId,
                    'auth_type' => $identity->provider,
                    'password' => $this->hasher->make(Str::password(40)),
                    'password_set_at' => null,
                    'email_verified_at' => now(),
                ]),
            );
        });

        if ($resolution->status === SocialLoginResolution::RESOLVED && $resolution->user) {
            $this->organizations->ensure($resolution->user);
        }

        return $resolution;
    }
}
