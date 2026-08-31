<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\Request;

class RequireLocalPasswordConfirmation
{
    public function __construct(private readonly RequirePassword $password) {}

    public function handle(Request $request, Closure $next): mixed
    {
        if (! $request->user()->hasLocalPassword()) {
            return $next($request);
        }

        return $this->password->handle($request, $next);
    }
}
