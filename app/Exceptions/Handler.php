<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Log\LogLevel;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use WeakMap;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<Throwable>, LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        $this->dontReportDuplicates();

        $this->reportable(function (Throwable $exception): void {
            $this->logIncident($exception);
        })->stop();

    }

    public function render($request, Throwable $exception): Response
    {
        $response = parent::render($request, $exception);
        if (config('app.debug') || $response->getStatusCode() !== 500) {
            return $response;
        }

        if (! ($this->reportedExceptionMap[$exception] ?? false)) {
            $this->logIncident($exception);
            $this->reportedExceptionMap[$exception] = true;
        }

        $incidentId = $this->incidentId($exception);
        $headers = [
            'Cache-Control' => 'no-store, private',
            'X-Incident-ID' => $incidentId,
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'An unexpected server error occurred.',
                'reference' => $incidentId,
            ], 500, $headers);
        }

        return response()->view('errors.500', [
            'incidentId' => $incidentId,
        ], 500, $headers);
    }

    private function incidentId(Throwable $exception): string
    {
        /** @var WeakMap<Throwable, string> $incidentIds */
        static $incidentIds;
        $incidentIds ??= new WeakMap;

        return $incidentIds[$exception] ??= (string) Str::uuid();
    }

    private function logIncident(Throwable $exception): void
    {
        Log::error('Unhandled application exception.', [
            'incident_id' => $this->incidentId($exception),
            'exception' => $exception,
        ]);
    }
}
