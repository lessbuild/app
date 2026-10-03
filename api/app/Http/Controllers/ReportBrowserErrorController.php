<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Admin\SelfMonitoring;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class ReportBrowserErrorController
{
    /**
     * Take a JavaScript error one of the platform's pages hit and report it into the platform's own Monitoring.
     *
     * @param  Request  $request
     * @param  SelfMonitoring  $monitoring
     * @return Response
     */
    public function __invoke(Request $request, SelfMonitoring $monitoring): Response
    {
        $error = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
            'source' => ['nullable', 'string', 'max:2048'],
            'line' => ['nullable', 'integer', 'min:0'],
            'column' => ['nullable', 'integer', 'min:0'],
            'stack' => ['nullable', 'string', 'max:8000'],
            'page' => ['nullable', 'string', 'max:2048'],
        ]);
        $page = is_string($error['page'] ?? null) ? $error['page'] : null;
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);
        if ($page === null || parse_url($page, PHP_URL_HOST) === $host) {
            $route = $page !== null ? rescue(fn () => app('router')->getRoutes()->match(Request::create($page))->getName(), null, false) : null;
            $monitoring->reportBrowserError($error, is_string($route) ? $route : null);
        }

        return response()->noContent();
    }
}
