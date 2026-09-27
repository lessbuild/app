<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Telemetry\TelemetryPayloadGuard;
use Closure;
use Illuminate\Http\Request;
use JsonException;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class DecodeTelemetryPayload
{
    /**
     * Reads telemetry bodies safely before the framework parses them.
     *
     * @param  TelemetryPayloadGuard  $guard  Checks JSON complexity, numbers and record counts.
     */
    public function __construct(private readonly TelemetryPayloadGuard $guard) {}

    /**
     * For telemetry POSTs, insists on JSON, reads at most the wire-size limit, gunzips within the decoded-size limit,
     * checks nesting, complexity and record counts, and hands the decoded object to the request. Each limit has its own
     * status and message, so clients can tell what to fix.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST') || ! $request->is('api/v1/ingest', 'api/v1/otlp/v1/*', 'api/v1/deployments')) {
            return $next($request);
        }

        $mediaType = strtolower(trim(explode(';', $request->header('Content-Type', ''))[0]));
        abort_unless($mediaType === 'application/json', 415, 'Telemetry requests must use application/json.');

        $encoding = strtolower(trim((string) $request->header('Content-Encoding', 'identity')));
        abort_unless(in_array($encoding, ['identity', 'gzip'], true), 415, 'Only identity and gzip content encodings are supported.');

        $maxBytes = (int) config('monitoring.telemetry.max_request_bytes');
        $maxDecodedBytes = (int) config('monitoring.telemetry.max_decoded_bytes');
        abort_if((int) $request->header('Content-Length', '0') > $maxBytes, 413, 'Telemetry request exceeds the wire-size limit.');

        $body = stream_get_contents($request->getContent(true), $maxBytes + 1);
        abort_if($body === false, 400, 'Telemetry request could not be read.');
        abort_if(strlen($body) > $maxBytes, 413, 'Telemetry request exceeds the wire-size limit.');

        if ($encoding === 'gzip') {
            $body = $this->decompress($body, $maxDecodedBytes);
        }

        abort_if(strlen($body) > $maxDecodedBytes, 413, 'Telemetry request exceeds the decoded-size limit.');
        $this->guard->assertJsonComplexity($body);

        try {
            $payload = json_decode($body, true, max(1, (int) config('monitoring.telemetry.max_json_depth')), JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $exception) {
            throw new HttpException(
                $exception->getCode() === JSON_ERROR_DEPTH ? 413 : 400,
                $exception->getCode() === JSON_ERROR_DEPTH
                    ? 'Telemetry request exceeds the nesting limit.'
                    : 'Telemetry request must contain valid UTF-8 JSON.',
            );
        }

        abort_unless(is_array($payload) && str_starts_with(ltrim($body), '{'), 400, 'Telemetry request must contain a JSON object.');
        $this->guard->assertFiniteNumbers($payload);
        $this->guard->assertRecordCount($payload, basename($request->path()));
        $request->setJson(new InputBag($payload));

        return $next($request);
    }

    /**
     * Gunzips in 1 KiB steps, stopping as soon as the output passes the limit, so a small compressed bomb can't expand
     * in memory. Concatenated gzip members are accepted; truncated or invalid data is a 400.
     */
    private function decompress(string $body, int $maxBytes): string
    {
        $context = inflate_init(ZLIB_ENCODING_GZIP);
        abort_if($context === false, 400, 'Telemetry request contains invalid gzip data.');
        $decoded = '';
        $offset = 0;
        $length = strlen($body);

        while ($offset < $length) {
            $previousRead = inflate_get_read_len($context);
            $chunk = @inflate_add($context, substr($body, $offset, 1024), ZLIB_SYNC_FLUSH);
            $consumed = inflate_get_read_len($context) - $previousRead;

            abort_if($chunk === false || $consumed === 0, 400, 'Telemetry request contains invalid gzip data.');
            $decoded .= $chunk;
            abort_if(strlen($decoded) > $maxBytes, 413, 'Telemetry request exceeds the decoded-size limit.');
            $offset += $consumed;

            if (inflate_get_status($context) === ZLIB_STREAM_END) {
                if ($offset === $length) {
                    return $decoded;
                }

                $context = inflate_init(ZLIB_ENCODING_GZIP);
                abort_if($context === false, 400, 'Telemetry request contains invalid gzip data.');
            }
        }

        abort(400, 'Telemetry request contains incomplete gzip data.');
    }
}
