<?php

declare(strict_types=1);

use App\Domain\Accounts\Exceptions\AccountRuleViolation;
use App\Domain\Identity\Exceptions\IdentityRuleViolation;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // Domain rule violations are authorised requests that break an invariant: show them like validation errors.
        $exceptions->map(
            AccountRuleViolation::class,
            fn (AccountRuleViolation $violation): ValidationException => ValidationException::withMessages([$violation->field => $violation->getMessage()]),
        );
        $exceptions->map(
            IdentityRuleViolation::class,
            fn (IdentityRuleViolation $violation): ValidationException => ValidationException::withMessages([$violation->field => $violation->getMessage()]),
        );
    })->create();
