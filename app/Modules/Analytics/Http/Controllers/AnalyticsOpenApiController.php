<?php

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Services\Core\AnalyticsApiDocumentationProvider;
use Illuminate\Http\JsonResponse;

final class AnalyticsOpenApiController
{
    public function __invoke(AnalyticsApiDocumentationProvider $documentation): JsonResponse
    {
        $reference = $documentation->reference();
        abort_if($reference === null, 404);

        return response()->json($reference->document)
            ->header('Cache-Control', 'public, max-age=300');
    }
}
