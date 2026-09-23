<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Services\CurrentWorkspace;
use App\Modules\Monitor\Services\OpenApiDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class ApiDocumentationController extends Controller
{
    public function json(OpenApiDocument $document): JsonResponse
    {
        return response()->json($document->make(url('/')))
            ->header('Cache-Control', 'public, max-age=300');
    }

    public function show(CurrentWorkspace $currentWorkspace, OpenApiDocument $document): View
    {
        return view('monitor::settings.api', [
            'workspace' => $currentWorkspace->get(),
            'document' => $document->make(url('/')),
            'openApiUrl' => route('monitor.api.openapi'),
            'ingestUrl' => route('monitor.api.ingest'),
        ]);
    }
}
