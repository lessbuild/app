<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialProvider;
use App\Services\SocialSignIn\SocialSignInGateway;
use Symfony\Component\HttpFoundation\Response;

/** Sign in (or sign up) with GitHub, GitLab or Bitbucket. */
final class RedirectToProviderController
{
    /**
     * Sends a guest to the provider's sign-in page.
     */
    public function __invoke(SocialProvider $provider, SocialSignInGateway $gateway): Response
    {
        if (! $gateway->configured($provider)) {
            return to_route('login')->withErrors(['social' => __(':provider sign-in isn’t available yet.', ['provider' => $provider->label()])]);
        }

        return $gateway->redirect($provider);
    }
}
