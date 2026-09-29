<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Services\Admin\PlatformStatus;
use Illuminate\Http\Response;

final class ShowStatusBadgeController
{
    /**
     * Draw a small status badge (an SVG to put in a README or footer) saying whether the platform is up.
     *
     * @param  PlatformStatus  $status
     * @return Response
     */
    public function __invoke(PlatformStatus $status): Response
    {
        [$label, $colour] = $status->snapshot()['operational'] ? ['operational', '#129b78'] : ['degraded', '#d97706'];
        $name = e(strtolower((string) config('app.name')));
        $left = 7 * mb_strlen($name) + 14;
        $right = 7 * mb_strlen($label) + 14;
        $width = $left + $right;
        $nameX = $left / 2;
        $labelX = $left + $right / 2;
        $svg = <<<SVG
            <svg xmlns="http://www.w3.org/2000/svg" width="{$width}" height="20" role="img" aria-label="{$name}: {$label}"><title>{$name}: {$label}</title><rect width="{$left}" height="20" fill="#172a4b"/><rect x="{$left}" width="{$right}" height="20" fill="{$colour}"/><g fill="#fff" font-family="Verdana,Geneva,sans-serif" font-size="11" text-anchor="middle"><text x="{$nameX}" y="14">{$name}</text><text x="{$labelX}" y="14">{$label}</text></g></svg>
            SVG;

        return response($svg, 200, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'public, max-age=60', 'X-Content-Type-Options' => 'nosniff']);
    }
}
