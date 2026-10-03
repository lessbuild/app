<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialProvider;
use App\Models\User;
use App\Services\SocialSignIn\ProviderIntents;
use App\Services\SocialSignIn\SocialSignInGateway;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Confirm identity for sudo mode by signing in again with a connected provider. */
final class ConfirmWithProviderController
{
    /**
     * Start a provider sign-in to confirm identity instead of typing a password: answer with the provider's address
     * for the app to send the browser to, and come back to `redirect` (a path in the app) afterwards.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  SocialProvider  $provider
     * @param  SocialSignInGateway  $gateway
     * @param  ProviderIntents  $intents
     * @return JsonResponse
     *
     * @throws ValidationException
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, SocialProvider $provider, SocialSignInGateway $gateway, ProviderIntents $intents): JsonResponse
    {
        if (! $gateway->configured($provider)) {
            throw ValidationException::withMessages(['social' => __(':provider sign-in isn’t available yet.', ['provider' => $provider->label()])]);
        }
        $back = $request->input('redirect');
        if (is_string($back) && str_starts_with($back, '/') && ! str_starts_with($back, '//')) {
            $request->session()->put('url.intended', $back);
        }
        $intents->start($request, $user, $provider, ProviderIntents::CONFIRM);
        $redirect = $gateway->redirect($provider);

        return response()->json(['redirect' => $redirect instanceof RedirectResponse ? $redirect->getTargetUrl() : (string) $redirect->headers->get('Location')]);
    }
}
