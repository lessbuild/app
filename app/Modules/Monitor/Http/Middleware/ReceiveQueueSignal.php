<?php

namespace App\Modules\Monitor\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use JsonException;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Response;

class ReceiveQueueSignal
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST') || ! $request->is('api/v1/queues/*')) {
            return $next($request);
        }
        $mediaType = strtolower(trim(explode(';', $request->header('Content-Type', ''))[0]));
        abort_unless($mediaType === 'application/json', 415, 'Queue signals must use application/json.');
        abort_unless(strtolower(trim($request->header('Content-Encoding', 'identity'))) === 'identity',
            415, 'Queue signals do not support compressed bodies.');
        abort_if((int) $request->header('Content-Length', 0) > 2048, 413, 'Queue signals are limited to 2 KiB.');
        $body = stream_get_contents($request->getContent(true), 2049);
        abort_if($body === false, 400, 'The queue signal could not be read.');
        abort_if(strlen($body) > 2048, 413, 'Queue signals are limited to 2 KiB.');
        try {
            $data = json_decode($body, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            abort(400, 'The queue signal must be a valid, shallow JSON object.');
        }
        abort_unless(is_array($data) && str_starts_with(ltrim($body), '{'), 400, 'The queue signal must be a JSON object.');
        $request->setJson(new InputBag($data));

        return $next($request);
    }
}
