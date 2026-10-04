<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Users\DisconnectSocialIdentity;
use App\Enums\SocialProvider;
use App\Exceptions\IdentityRuleViolation;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

final class DisconnectProviderController
{
    /**
     * Disconnect a provider account, unless it's the person's only way to sign in.
     *
     * @param  User  $user
     * @param  SocialProvider  $provider
     * @param  DisconnectSocialIdentity  $disconnect
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, SocialProvider $provider, DisconnectSocialIdentity $disconnect): JsonResponse
    {
        try {
            $disconnected = $disconnect->handle($user, $provider);
        } catch (IdentityRuleViolation $violation) {
            throw ValidationException::withMessages([$violation->field => $violation->getMessage()]);
        }

        return response()->json(['redirect' => route('settings.security', [], false), 'message' => $disconnected ? __('Account disconnected.') : __('That account wasn’t connected.')]);
    }
}
