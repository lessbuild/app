<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use JsonException;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * Heartbeat and queue signals (public contract from the old Monitor app): a small, shallow JSON object, read
 * before the framework parses the body so an oversized or malformed request is refused cheaply.
 */
final class ReceiveMonitorSignal
{
    public const MAX_BYTES = 2048;

    /**
     * Decode heartbeat and queue signals: insist on uncompressed JSON of at most 2 KiB and three levels deep, and hand
     * the decoded object to the request.
     *
     * @param  Request  $request
     * @param  Closure(Request): Response  $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST') || ! $request->is('api/v1/heartbeats/*', 'api/v1/queues/*')) {
            return $next($request);
        }
        $kind = $request->is('api/v1/heartbeats/*') ? 'Heartbeat' : 'Queue';
        $mediaType = strtolower(trim(explode(';', (string) $request->header('Content-Type', ''))[0]));
        abort_unless($mediaType === 'application/json', 415, "{$kind} signals must use application/json.");
        abort_unless(strtolower(trim((string) $request->header('Content-Encoding', 'identity'))) === 'identity',
            415, "{$kind} signals do not support compressed bodies.");
        abort_if((int) $request->header('Content-Length', '0') > self::MAX_BYTES, 413, "{$kind} signals are limited to 2 KiB.");
        $body = stream_get_contents($request->getContent(true), self::MAX_BYTES + 1);
        abort_if($body === false, 400, "The {$kind} signal could not be read.");
        abort_if(strlen($body) > self::MAX_BYTES, 413, "{$kind} signals are limited to 2 KiB.");
        try {
            $data = json_decode($body, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            abort(400, "The {$kind} signal must be a valid, shallow JSON object.");
        }
        abort_unless(is_array($data) && str_starts_with(ltrim($body), '{'), 400, "The {$kind} signal must be a JSON object.");
        $request->setJson(new InputBag($data));

        return $next($request);
    }
}
