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

/** Connect a provider to the signed-in user (from Security settings). */
final class ConnectProviderController
{
    /**
     * Starts connecting a provider account to the signed-in person.
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
            return to_route('settings.security')->withErrors(['social' => __(':provider sign-in isn’t available yet.', ['provider' => $provider->label()])], 'social');
        }
        $intents->start($request, $user, $provider, ProviderIntents::CONNECT);

        return $gateway->redirect($provider);
    }
}
