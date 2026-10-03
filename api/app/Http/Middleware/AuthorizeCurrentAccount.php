<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * `account.can:{ability}[,{class or route parameter}...]`: authorises against the signed-in person's current account, the way
 * `can` does for route models. Class names go first, then the account, then route parameters, e.g.
 * `account.can:create,App\Models\Project` checks `create` with [Project::class, $account].
 */
final class AuthorizeCurrentAccount
{
    /**
     * Authorise the ability against the current account, passing class names and route parameters listed after it.
     * People without a current account get a 404.
     *
     * @param  Request  $request
     * @param  Closure  $next
     * @param  string  $ability
     * @param  string  ...$extra
     * @return Response
     */
    public function handle(Request $request, Closure $next, string $ability, string ...$extra): Response
    {
        $user = $request->user();
        $account = ($user instanceof User ? $user->currentAccount : null) ?? abort(404);
        $classes = array_values(array_filter($extra, fn (string $item): bool => class_exists($item)));
        $parameters = array_map(fn (string $item): mixed => $request->route($item), array_values(array_filter($extra, fn (string $item): bool => ! class_exists($item))));
        Gate::authorize($ability, [...$classes, $account, ...$parameters]);

        return $next($request);
    }
}
