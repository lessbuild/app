<?php

declare(strict_types=1);

namespace App\Http\Controllers\Docs;

use App\Support\Api\OpenApi;
use App\Support\Site\PageMeta;
use App\Support\StructuredData;
use Illuminate\Http\JsonResponse;

final class ShowApiReferenceController
{
    /**
     * Show the public API's operations by tag (method, path, what each does and the token scope it needs), from the
     * OpenAPI description, with where to get that description.
     *
     * @return JsonResponse
     */
    public function __invoke(): JsonResponse
    {
        $document = OpenApi::document();

        return response()->json([
            'meta' => PageMeta::for(__('API reference'), __('The platform’s public API: Deployer API v1, Monitoring ingest, heartbeats and queues, and the analytics tracker.'), route('docs.api'), null, [
                StructuredData::breadcrumbs([config('app.name') => route('home'), __('API reference') => route('docs.api')]),
            ]),
            'description' => (string) ($document['info']['description'] ?? ''),
            'groups' => collect(OpenApi::operations())->groupBy('tag')->map(fn ($operations, string $tag): array => ['tag' => $tag, 'operations' => $operations->values()])->values(),
            'openApiUrl' => route('docs.openapi'),
        ]);
    }
}
