<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Api\Models\ApiToken;
use App\Domain\Api\Queries\TokenAccountQuery;
use App\Domain\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Runs after auth:sanctum; puts the token's account on the request as the `account` attribute. */
final class ResolveTokenAccount
{
    public function __construct(private readonly TokenAccountQuery $query) {}

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
