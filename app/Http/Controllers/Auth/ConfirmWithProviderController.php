<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialProvider;
use App\Models\User;
use App\Services\SocialSignIn\ProviderIntents;
use App\Services\SocialSignIn\SocialSignInGateway;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Confirm identity for sudo mode by signing in again with a connected provider. */
final class ConfirmWithProviderController
{
    /**
     * Starts a provider sign-in to confirm identity instead of typing a password.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  SocialProvider  $provider
     * @param  SocialSignInGateway  $gateway
     * @param  ProviderIntents  $intents
     * @return Response
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, SocialProvider $provider, SocialSignInGateway $gateway, ProviderIntents $intents): Response
    {
        if (! $gateway->configured($provider)) {
            return to_route('password.confirm')->withErrors(['social' => __(':provider sign-in isn’t available yet.', ['provider' => $provider->label()])], 'social');
        }
        $intents->start($request, $user, $provider, ProviderIntents::CONFIRM);

        return $gateway->redirect($provider);
    }
}
