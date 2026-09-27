<?php

declare(strict_types=1);

use App\Exceptions\AccountRuleViolation;
use App\Exceptions\AnalyticsRuleViolation;
use App\Exceptions\BillingRuleViolation;
use App\Exceptions\IdentityRuleViolation;
use App\Exceptions\ProjectRuleViolation;
use App\Http\Middleware\EnsureServiceEnabled;
use App\Http\Middleware\ProjectContext;
use App\Http\Middleware\ReceiveMonitorSignal;
use App\Http\Middleware\ResolveTokenAccount;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Stripe signs its webhooks; there is no session or CSRF token.
        $middleware->validateCsrfTokens(except: ['webhooks/stripe']);
        // Monitor signals are checked byte for byte; monitor secrets are stored exactly as typed.
        $middleware->prepend(ReceiveMonitorSignal::class);
        $signal = fn (Request $request): bool => $request->is('api/v1/heartbeats/*', 'api/v1/queues/*');
        $middleware->trimStrings(except: [$signal, 'request_url', 'bearer_token', 'body_contains', 'hostname', 'dns_expected', 'heartbeat_cron', 'endpoint_url', 'signing_secret']);
        $middleware->convertEmptyStringsToNull(except: [$signal]);
        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
            'token.account' => ResolveTokenAccount::class,
            'project.context' => ProjectContext::class,
            'service' => EnsureServiceEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['endpoint_url', 'signing_secret', 'request_url', 'bearer_token', 'body_contains', 'hostname', 'dns_expected']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // Domain rule violations are authorised requests that break an invariant: show them like validation errors.
        $exceptions->map(
            AccountRuleViolation::class,
            fn (AccountRuleViolation $violation): ValidationException => ValidationException::withMessages([$violation->field => $violation->getMessage()]),
        );
        $exceptions->map(
            ProjectRuleViolation::class,
            fn (ProjectRuleViolation $violation): ValidationException => ValidationException::withMessages([$violation->field => $violation->getMessage()]),
        );
        $exceptions->map(
            AnalyticsRuleViolation::class,
            fn (AnalyticsRuleViolation $violation): ValidationException => ValidationException::withMessages([$violation->field => $violation->getMessage()]),
        );
        $exceptions->map(
            BillingRuleViolation::class,
            fn (BillingRuleViolation $violation): ValidationException => ValidationException::withMessages([$violation->field => $violation->getMessage()]),
        );
        $exceptions->map(
            IdentityRuleViolation::class,
            fn (IdentityRuleViolation $violation): ValidationException => ValidationException::withMessages([$violation->field => $violation->getMessage()]),
        );
    })->create();
