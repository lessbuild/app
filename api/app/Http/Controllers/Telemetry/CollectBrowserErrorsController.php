<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telemetry;

use App\Contracts\Telemetry\TelemetryIngestor;
use App\Data\Telemetry\IngestContext;
use App\Models\Environment;
use App\Support\Analytics\UserAgent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/** POST /api/v1/browser/{key}/errors: JavaScript errors from customers' pages, sent by /monitoring/browser.js. */
final class CollectBrowserErrorsController
{
    /**
     * Accept up to ten JavaScript errors from a page on one of the environment's origins and record them as exceptions
     * from the "browser" service, grouped into issues by message and where they were thrown. Unknown keys and other
     * origins get a 404 or 403; preflight requests get the CORS headers.
     *
     * @param  Request  $request
     * @param  string  $key
     * @param  TelemetryIngestor  $ingestor
     * @return Response
     */
    public function __invoke(Request $request, string $key, TelemetryIngestor $ingestor): Response
    {
        $environment = Environment::query()->where('browser_key', $key)->first();
        abort_if($environment === null, 404);
        $origin = mb_strtolower(rtrim((string) $request->header('Origin'), '/'));
        abort_unless(in_array($origin, $environment->browser_origins ?? [], true), 403);
        $headers = ['Access-Control-Allow-Origin' => $origin, 'Access-Control-Allow-Methods' => 'POST', 'Access-Control-Allow-Headers' => 'Content-Type', 'Access-Control-Max-Age' => '86400', 'Vary' => 'Origin'];
        if ($request->isMethod('OPTIONS')) {
            return response()->noContent(204, $headers);
        }
        $data = $request->validate([
            'release' => ['nullable', 'string', 'max:100'],
            'errors' => ['required', 'array', 'list', 'min:1', 'max:10'],
            'errors.*.message' => ['required', 'string', 'max:1000'],
            'errors.*.source' => ['nullable', 'string', 'max:2048'],
            'errors.*.line' => ['nullable', 'integer', 'min:0'],
            'errors.*.column' => ['nullable', 'integer', 'min:0'],
            'errors.*.stack' => ['nullable', 'string', 'max:8000'],
            'errors.*.page' => ['nullable', 'string', 'max:2048'],
            'errors.*.kind' => ['nullable', 'string', 'in:error,unhandledrejection'],
        ]);
        $agent = UserAgent::parse((string) $request->userAgent());
        $events = [];
        foreach ($data['errors'] as $error) {
            $where = ($error['source'] ?? '').':'.($error['line'] ?? '').':'.($error['column'] ?? '');
            $page = isset($error['page']) ? (string) preg_replace('/[?#].*$/', '', $error['page']) : null;
            $events[] = [
                'id' => (string) Str::uuid(), 'type' => 'exception', 'severity' => 'error', 'service' => 'browser',
                'name' => ($error['kind'] ?? 'error') === 'unhandledrejection' ? 'Unhandled promise rejection' : 'JavaScript error',
                'title' => Str::limit($error['message'], 255, ''),
                'fingerprint' => sha1('browser|'.$error['message'].'|'.preg_replace('/\?.*$/', '', $where)),
                'timestamp' => now('UTC')->toIso8601String(),
                'route' => $page !== null ? Str::limit((string) (parse_url($page, PHP_URL_PATH) ?: '/'), 255, '') : null,
                'url' => $page !== null ? Str::limit($page, 2048, '') : null,
                'details' => Str::limit($error['message'].($where !== '::' ? ' at '.$where : '')."\n".($error['stack'] ?? ''), 10000, ''),
                'attributes' => array_filter(['service.version' => $data['release'] ?? null, 'browser' => $agent['browser'], 'browser.version' => $agent['browser_version'], 'os' => $agent['os'], 'device' => $agent['device']]),
            ];
        }
        $ingestor->ingest($environment, 'browser-'.Str::uuid(), $events, new IngestContext(explicitBatch: false));

        return response()->noContent(202, $headers);
    }
}
