<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** `verified`: people who haven't verified their email go to the page that asks them to, for the app's API too. */
final class EnsureEmailIsVerified
{
    /**
     * Send people with an unverified email to the verification page.
     *
     * @param  Request  $request
     * @param  Closure(Request): Response  $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            return redirect()->guest(route('verification.notice', [], false));
        }

        return $next($request);
    }
}
