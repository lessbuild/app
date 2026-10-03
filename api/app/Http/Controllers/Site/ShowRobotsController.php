<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use Illuminate\Http\Response;

final class ShowRobotsController
{
    /**
     * Let search engines crawl the public site, keep them out of the app, and point them at the sitemap.
     *
     * @return Response
     */
    public function __invoke(): Response
    {
        $lines = ['User-agent: *', 'Disallow: /projects/', 'Disallow: /account/', 'Disallow: /settings/', 'Disallow: /admin', 'Disallow: /notifications', '', 'Sitemap: '.route('sitemap')];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }
}
