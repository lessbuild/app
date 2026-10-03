<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialProvider;
use App\Services\SocialSignIn\SocialSignInGateway;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Sign in (or sign up) with GitHub, GitLab or Bitbucket. */
final class RedirectToProviderController
{
    /**
     * Send a guest to the provider's sign-in page.
     *
     * @param  Request  $request
     * @param  SocialProvider  $provider
     * @param  SocialSignInGateway  $gateway
     * @return Response
     */
    public function __invoke(Request $request, SocialProvider $provider, SocialSignInGateway $gateway): Response
    {
        // An access invitation (?invite=) rides along so signing up with the provider honours it while registration is closed.
        $invite = $request->query('invite');
        if (is_string($invite) && strlen($invite) === 64) {
            $request->session()->put('registration.invite', $invite);
        }
        if (! $gateway->configured($provider)) {
            return to_route('login')->withErrors(['social' => __(':provider sign-in isn’t available yet.', ['provider' => $provider->label()])]);
        }

        return $gateway->redirect($provider);
    }
}
