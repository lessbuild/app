<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ScimException;
use App\Models\Account;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Finds the account from its SCIM bearer token and hands it to the SCIM controllers as the `scim.account` attribute. */
final class AuthenticateScim
{
    /**
     * Let the request through only with a valid SCIM token.
     *
     * @param  Request  $request
     * @param  Closure(Request): Response  $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->bearerToken();
        $account = $token === '' ? null : Account::query()->where('scim_token_hash', hash('sha256', $token))->first();
        if ($account === null) {
            return (new ScimException(401, 'The SCIM token isn’t valid.'))->render();
        }
        $request->attributes->set('scim.account', $account);

        return $next($request);
    }
}
