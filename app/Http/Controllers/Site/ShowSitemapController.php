<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Platform\ServiceRegistry;
use Illuminate\Http\Response;

final class ShowSitemapController
{
    /**
     * List every public page for search engines, cached for an hour.
     *
     * @param  ServiceRegistry  $services
     * @return Response
     */
    public function __invoke(ServiceRegistry $services): Response
    {
        $urls = [route('home'), route('pricing'), route('help'), route('changelog'), route('docs.api'), route('platform.status'), route('legal', 'privacy'), route('legal', 'terms')];
        foreach ($services->all() as $service) {
            $urls[] = route('features', $service->key());
        }
        foreach (array_keys((array) config('help.guides')) as $guide) {
            $urls[] = route('help.guide', $guide);
        }
        foreach (array_keys((array) config('compare.competitors')) as $competitor) {
            $urls[] = route('compare', $competitor);
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($urls as $url) {
            $xml .= '  <url><loc>'.e($url).'</loc></url>'."\n";
        }

        return response($xml."</urlset>\n", 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }
}
