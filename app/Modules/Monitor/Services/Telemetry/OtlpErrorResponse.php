<?php

namespace App\Modules\Monitor\Services\Telemetry;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class OtlpErrorResponse
{
    public function render(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $request->is('api/v1/otlp/v1/*')) {
            return null;
        }

        if ($exception instanceof ValidationException) {
            $violations = [];

            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $violations[] = [
                        'field' => Str::limit($field, 256, ''),
                        'description' => Str::limit($message, 512, ''),
                    ];

                    if (count($violations) === 20) {
                        break 2;
                    }
                }
            }

            return response()->json([
                'code' => 3,
                'message' => 'The OTLP payload is invalid.',
                'details' => [[
                    '@type' => 'type.googleapis.com/google.rpc.BadRequest',
                    'fieldViolations' => $violations,
                ]],
            ], 400);
        }

        $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
        $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];
        $message = $status >= 500
            ? 'Telemetry could not be accepted.'
            : ($exception->getMessage() ?: 'The telemetry request failed.');
        $code = match ($status) {
            400 => 3,
            401 => 16,
            403 => 7,
            404 => 5,
            413, 429 => 8,
            405, 415 => 12,
            502, 503, 504 => 14,
            default => 13,
        };

        return response()->json(['code' => $code, 'message' => Str::limit($message, 512, '')], $status, $headers);
    }
}
