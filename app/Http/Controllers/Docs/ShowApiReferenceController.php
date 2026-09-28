<?php

declare(strict_types=1);

namespace App\Http\Controllers\Docs;

use App\Support\Api\OpenApi;
use Illuminate\Http\Response;

final class ShowApiReferenceController
{
    /**
     * Show the API reference: each operation grouped by area, with the scope it needs, and a link to the OpenAPI JSON.
     *
     * @return Response
     */
    public function __invoke(): Response
    {
        $document = OpenApi::document();

        return response()->view('docs.api', [
            'description' => (string) ($document['info']['description'] ?? ''),
            'groups' => collect(OpenApi::operations())->groupBy('tag')->all(),
        ])->header('Cache-Control', 'public, max-age=300');
    }
}
