<?php

declare(strict_types=1);

namespace App\Http\Controllers\Docs;

use App\Support\Api\OpenApi;
use Illuminate\Http\JsonResponse;

final class ShowOpenApiController
{
    /**
     * Serve the API's OpenAPI description as JSON, cached publicly for an hour.
     *
     * @return JsonResponse
     */
    public function __invoke(): JsonResponse
    {
        return response()->json(OpenApi::document())->header('Cache-Control', 'public, max-age=3600')->header('Access-Control-Allow-Origin', '*');
    }
}
