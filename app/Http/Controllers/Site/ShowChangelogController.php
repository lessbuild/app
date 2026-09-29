<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use Illuminate\Http\Response;

final class ShowChangelogController
{
    /**
     * Show what's new, newest first, cached publicly for five minutes.
     *
     * @return Response
     */
    public function __invoke(): Response
    {
        return response()->view('site.changelog', ['entries' => (array) config('changelog')])->header('Cache-Control', 'public, max-age=300');
    }
}
