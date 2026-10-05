<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Support\Site\Comparisons;
use App\Support\Site\PageMeta;
use App\Support\StructuredData;
use Illuminate\Http\JsonResponse;

final class ShowComparisonsController
{
    /**
     * Show every comparison page in brief, for the page that lists them.
     *
     * @param  Comparisons  $comparisons
     * @return JsonResponse
     */
    public function __invoke(Comparisons $comparisons): JsonResponse
    {
        $title = __('Coming from another tool?');

        return response()->json([
            'meta' => PageMeta::for(__(':app compared with other tools', ['app' => config('app.name')]), __('How :app compares with Laravel Forge, Laravel Cloud, Ploi, Vercel, Plausible, Google Analytics and Laravel Nightwatch: what’s the same, what’s different, and when the other tool is the better pick.', ['app' => config('app.name')]), route('compare.index'), null, [
                StructuredData::breadcrumbs([config('app.name') => route('home'), $title => route('compare.index')]),
            ]),
            'comparisons' => $comparisons->all(),
            'checked' => (string) config('compare.checked'),
        ])->header('Vary', 'Accept-Language');
    }
}
