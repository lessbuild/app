<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Services\CurrentWorkspace;
use App\Modules\Monitor\Services\OpenApiDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

class ApiDocumentationController extends Controller
{
    public function json(OpenApiDocument $document): JsonResponse
    {
        return response()->json($document->make(url('/')))
            ->header('Cache-Control', 'public, max-age=300');
    }

    public function show(CurrentWorkspace $currentWorkspace, OpenApiDocument $document): View|RedirectResponse
    {
        if (Route::has('core.help.monitor.api')) {
            $coreUrl = route('core.help.monitor.api');

            if ($this->origin($coreUrl) !== $this->origin(request()->getSchemeAndHttpHost())) {
                return redirect()->to($coreUrl, 301);
            }
        }

        return view('monitor::settings.api', [
            'workspace' => $currentWorkspace->get(),
            'document' => $document->make(url('/')),
            'openApiUrl' => route('monitor.api.openapi'),
            'ingestUrl' => route('monitor.api.ingest'),
        ]);
    }

    private function origin(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $origin = $scheme.'://'.strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if ($port !== null && ! (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
            $origin .= ':'.$port;
        }

        return $origin;
    }
}
