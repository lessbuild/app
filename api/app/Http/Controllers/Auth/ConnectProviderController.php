<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialProvider;
use App\Models\User;
use App\Services\SocialSignIn\ProviderIntents;
use App\Services\SocialSignIn\SocialSignInGateway;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Connect a provider to the signed-in user (from Security settings). */
final class ConnectProviderController
{
    /**
     * Start connecting a provider account to the signed-in person.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  SocialProvider  $provider
     * @param  SocialSignInGateway  $gateway
     * @param  ProviderIntents  $intents
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, SocialProvider $provider, SocialSignInGateway $gateway, ProviderIntents $intents): JsonResponse
    {
        if (! $gateway->configured($provider)) {
            throw ValidationException::withMessages(['social' => __(':provider sign-in isn’t available yet.', ['provider' => $provider->label()])]);
        }
        $intents->start($request, $user, $provider, ProviderIntents::CONNECT);

        // The browser goes to the provider; its callback (routes/web.php) connects the account and returns to settings.
        return response()->json(['redirect' => $gateway->redirect($provider)->getTargetUrl()]);
    }
}
