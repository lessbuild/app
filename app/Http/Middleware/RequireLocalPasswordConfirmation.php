<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\Request;

class RequireLocalPasswordConfirmation
{
    /**
     * Delegate confirmation expiry and redirects to Laravel's password-confirmation middleware.
     */
    public function __construct(private readonly RequirePassword $password) {}

    /**
     * Require recent password confirmation only for accounts with a local password.
     *
     * @param  Closure(Request): mixed  $next  The remaining request pipeline.
     * @return mixed The downstream result or Laravel's password-confirmation response.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        if (! $request->user()->hasLocalPassword()) {
            return $next($request);
        }

        return $this->password->handle($request, $next);
    }
}
