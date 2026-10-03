<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use Illuminate\Http\Response;

final class ShowLegalPageController
{
    /**
     * Show the privacy policy or the terms of service, cached publicly for five minutes.
     *
     * @param  string  $page
     * @return Response
     */
    public function __invoke(string $page): Response
    {
        $copy = config('legal.pages.'.$page);
        abort_unless(is_array($copy), 404);

        return response()->view('site.legal', ['page' => $page, 'copy' => $copy])->header('Cache-Control', 'public, max-age=300');
    }
}
