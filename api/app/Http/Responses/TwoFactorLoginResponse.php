<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/** Return the post-login destination to the Nuxt form as JSON instead of an empty 204. */
final class TwoFactorLoginResponse implements TwoFactorLoginResponseContract
{
    public function toResponse($request): SymfonyResponse
    {
        if ($request instanceof Request && $request->wantsJson()) {
            $redirect = redirect()->intended(Fortify::redirects('login'));

            return response()->json(['redirect' => $redirect->getTargetUrl()]);
        }

        return redirect()->intended(Fortify::redirects('login'));
    }
}
