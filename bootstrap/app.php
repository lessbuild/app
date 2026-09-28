<?php

declare(strict_types=1);

use App\Exceptions\RuleViolation;
use App\Exceptions\StateConflict;
use App\Http\Middleware\AuthenticateIngestToken;
use App\Http\Middleware\AuthorizeCurrentAccount;
use App\Http\Middleware\DecodeTelemetryPayload;
use App\Http\Middleware\EnforceAccountSecurity;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\EnsureServiceEnabled;
use App\Http\Middleware\ProjectContext;
use App\Http\Middleware\ReceiveMonitorSignal;
use App\Http\Middleware\ResolveTokenAccount;
use App\Services\Telemetry\OtlpErrorResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Stripe signs its webhooks; there is no session or CSRF token. One-click unsubscribe (RFC 8058) posts from the mail client with the token in the URL; provisioning scripts post signed callbacks.
        $middleware->validateCsrfTokens(except: ['webhooks/stripe', 'status/subscriptions/*/unsubscribe/*', 'servers/*/provisioning/callback/*', 'websites/*/provisioning/callback/*', 'builds/*/deployment/callback/*']);
        // Monitor signals are checked byte for byte; monitor secrets are stored exactly as typed.
        $middleware->prepend([ReceiveMonitorSignal::class, DecodeTelemetryPayload::class]);
        $signal = fn (Request $request): bool => $request->is('api/v1/heartbeats/*', 'api/v1/queues/*', 'api/v1/ingest', 'api/v1/otlp/v1/*', 'api/v1/deployments', 'servers/*/provisioning/callback/*', 'websites/*/provisioning/callback/*', 'builds/*/deployment/callback/*', 'api/repositories/*/webhook', 'api/github-app/webhook');
        // Terminal keystrokes (Enter, spaces, control characters) must reach the shell untouched.
        $terminal = fn (Request $request): bool => $request->is('projects/*/infrastructure/servers/*/terminal/*/input');
        $middleware->trimStrings(except: [$signal, $terminal, 'request_url', 'bearer_token', 'body_contains', 'hostname', 'dns_expected', 'heartbeat_cron', 'endpoint_url', 'signing_secret', 'env_file', 'ssh_private_key', 'token', 'script']);
        $middleware->convertEmptyStringsToNull(except: [$signal, $terminal]);
        $middleware->alias([
            'account.can' => AuthorizeCurrentAccount::class,
            'account.security' => EnforceAccountSecurity::class,
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
            'token.account' => ResolveTokenAccount::class,
            'project.context' => ProjectContext::class,
            'service' => EnsureServiceEnabled::class,
            'ingest.token' => AuthenticateIngestToken::class,
            'platform.admin' => EnsurePlatformAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Reported errors also go to the platform's own Monitoring once `platform:self-monitor` has set it up.
        $exceptions->report(function (Throwable $exception): void {
            app(App\Services\Admin\SelfMonitoring::class)->report($exception);
        });
        // OTLP clients expect google.rpc.Status bodies.
        $exceptions->render((new OtlpErrorResponse)->render(...));
        $exceptions->dontFlash(['endpoint_url', 'signing_secret', 'request_url', 'bearer_token', 'body_contains', 'hostname', 'dns_expected']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // Domain rule violations are authorised requests that break an invariant: show them like validation errors.
        $exceptions->map(RuleViolation::class, fn (RuleViolation $violation): ValidationException => ValidationException::withMessages([$violation->field => $violation->getMessage()]));
        $exceptions->map(StateConflict::class, fn (StateConflict $conflict): ConflictHttpException => new ConflictHttpException($conflict->getMessage(), $conflict));
    })->create();
