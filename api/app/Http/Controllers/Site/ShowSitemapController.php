<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Platform\ServiceRegistry;
use App\Support\Changelog;
use Illuminate\Http\Response;

final class ShowSitemapController
{
    /**
     * List every public page for search engines, with when it last changed where that's known, cached for an hour.
     *
     * @param  ServiceRegistry  $services
     * @return Response
     */
    public function __invoke(ServiceRegistry $services): Response
    {
        $released = Changelog::latestDate();
        $effective = config('legal.effective_date');
        $effective = is_string($effective) ? $effective : null;

        // Each URL => the date it last changed, or null when it isn't tracked.
        $urls = [route('home') => $released, route('pricing') => null, route('help') => null, route('changelog') => $released, route('roadmap') => null,
            route('docs.api') => null, route('platform.status') => null, route('legal', 'privacy') => $effective, route('legal', 'terms') => $effective];
        foreach ($services->all() as $service) {
            $urls[route('features', $service->key())] = $released;
        }
        foreach (array_keys((array) config('help.guides')) as $guide) {
            $urls[route('help.guide', $guide)] = null;
        }
        $urls[route('compare.index')] = null;
        foreach (array_keys((array) config('compare.competitors')) as $competitor) {
            $urls[route('compare', $competitor)] = null;
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($urls as $url => $lastModified) {
            $xml .= '  <url><loc>'.e($url).'</loc>'.($lastModified !== null ? '<lastmod>'.e($lastModified).'</lastmod>' : '').'</url>'."\n";
        }

        return response($xml."</urlset>\n", 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }
}
