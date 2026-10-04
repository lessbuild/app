<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\SocialProvider;
use App\Models\User;
use App\Models\UserSshKey;
use App\Queries\Users\SecuritySettingsQuery;
use App\Services\SocialSignIn\SocialSignInGateway;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `GET /api/app/settings/security`. */
final class ShowSecurityController
{
    /**
     * Return the person's sign-in security: password, two-factor authentication (with the QR code while it's being
     * set up), passkeys, connected providers and SSH keys. Recovery codes are fetched separately, from Fortify.
     *
     * @param  User  $user
     * @param  SecuritySettingsQuery  $query
     * @param  SocialSignInGateway  $gateway
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, SecuritySettingsQuery $query, SocialSignInGateway $gateway): JsonResponse
    {
        $security = $query->handle($user);

        return response()->json([
            'email' => $user->email,
            'security' => $security,
            'providers' => array_map(function (SocialProvider $provider) use ($gateway, $security): array {
                $identity = array_find($security->socialIdentities, fn ($identity): bool => $identity->provider === $provider);

                return [
                    'key' => $provider->value,
                    'label' => $provider->label(),
                    'configured' => $gateway->configured($provider),
                    'email' => $identity?->email,
                    'connectedAt' => $identity?->connectedAt?->toIso8601String(),
                    'connected' => $identity !== null,
                ];
            }, SocialProvider::cases()),
            'sshKeys' => UserSshKey::query()->where('user_id', $user->id)->orderBy('name')->get()->map(fn (UserSshKey $key): array => [
                'id' => $key->id,
                'name' => $key->name,
                'fingerprint' => $key->fingerprint,
                'createdAt' => $key->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }
}
