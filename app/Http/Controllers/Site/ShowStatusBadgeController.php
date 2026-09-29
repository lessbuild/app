<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Services\Admin\PlatformStatus;
use App\Support\StatusBadge;
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
        [$label, $colour] = $status->snapshot()['operational'] ? ['operational', StatusBadge::COLOURS['operational']] : ['degraded', StatusBadge::COLOURS['degraded']];

        return response(StatusBadge::svg(strtolower((string) config('app.name')), $label, $colour), 200, StatusBadge::headers());
    }
}
