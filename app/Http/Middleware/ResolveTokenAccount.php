<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Models\User;
use App\Queries\ApiTokens\TokenAccountQuery;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Runs after auth:sanctum; puts the token's account on the request as the `account` attribute. */
final class ResolveTokenAccount
{
    /**
     * Connects an API token to the account it acts in.
     *
     * @param  TokenAccountQuery  $query  Checks the token's creator still may use API tokens there.
     */
    public function __construct(private readonly TokenAccountQuery $query) {}

    /**
     * Puts the token's account on the request, or refuses with 403 once the creator has left or lost the right to use
     * tokens.
     *
     * @param  Request  $request
     * @param  Closure  $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user instanceof User ? $user->currentAccessToken() : null;
        if (! $user instanceof User || ! $token instanceof ApiToken) {
            abort(401);
        }

        $account = $this->query->handle($user, $token);
        abort_if($account === null, 403, __('This token no longer has access to its account.'));
        $request->attributes->set('account', $account);

        return $next($request);
    }
}
