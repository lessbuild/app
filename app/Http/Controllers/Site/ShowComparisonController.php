<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use Illuminate\Http\Response;

final class ShowComparisonController
{
    /**
     * Show how BuildPusher compares with another product, cached publicly for five minutes.
     *
     * @param  string  $competitor
     * @return Response
     */
    public function __invoke(string $competitor): Response
    {
        $copy = config('compare.competitors.'.$competitor);
        abort_unless(is_array($copy), 404);

        return response()->view('site.compare', ['slug' => $competitor, 'copy' => $copy, 'checked' => (string) config('compare.checked')])->header('Cache-Control', 'public, max-age=300');
    }
}
