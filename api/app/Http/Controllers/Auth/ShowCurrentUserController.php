<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialProvider;
use App\Models\User;
use App\Services\SocialSignIn\SocialSignInGateway;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `GET /api/app/auth/me`. */
final class ShowCurrentUserController
{
    /**
     * Return who's signed in, whether their email is verified, and how they can confirm it's them before a sensitive
     * change (password, passkey, or a social provider they connected). Guests get a 401.
     *
     * @param  User  $user
     * @param  SocialSignInGateway  $gateway
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, SocialSignInGateway $gateway): JsonResponse
    {
        $providers = [];
        foreach (SocialProvider::cases() as $provider) {
            if ($gateway->configured($provider) && $user->socialIdentities()->where('provider', $provider)->exists()) {
                $providers[] = ['key' => $provider->value, 'label' => $provider->label(), 'url' => route('app.auth.confirm-with', $provider->value, false)];
            }
        }

        return response()->json([
            'name' => $user->name,
            'email' => $user->email,
            'emailVerified' => $user->hasVerifiedEmail(),
            'hasPassword' => $user->password !== null,
            'hasPasskeys' => $user->hasPasskeysEnabled(),
            'confirmProviders' => $providers,
            'locale' => app()->getLocale(),
        ]);
    }
}
