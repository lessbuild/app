<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Support\Site\Copy;
use App\Support\Site\PageMeta;
use App\Support\StructuredData;
use Illuminate\Http\JsonResponse;

final class ShowComparisonController
{
    /**
     * Show how BuildPusher compares with another tool, and when to choose each. Unknown tools are a 404.
     *
     * @param  string  $competitor
     * @return JsonResponse
     */
    public function __invoke(string $competitor): JsonResponse
    {
        $copy = config('compare.competitors.'.$competitor);
        abort_unless(is_array($copy), 404);
        $title = __(':app vs :other', ['app' => config('app.name'), 'other' => $copy['name']]);
        $description = __('Looking for a :other alternative? How :app compares with :other: what both do, where they differ, and when to choose each.', ['app' => config('app.name'), 'other' => $copy['name']]);

        return response()->json([
            'meta' => PageMeta::for(__(':app vs :other: an alternative compared', ['app' => config('app.name'), 'other' => $copy['name']]), $description, route('compare', $competitor), null, [
                StructuredData::breadcrumbs([config('app.name') => route('home'), $title => route('compare', $competitor)]),
            ]),
            'slug' => $competitor,
            'copy' => [...Copy::translate($copy), 'name' => $copy['name']],
            'checked' => (string) config('compare.checked'),
        ])->header('Vary', 'Accept-Language');
    }
}
