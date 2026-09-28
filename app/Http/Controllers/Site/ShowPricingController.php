<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Platform\ServiceRegistry;
use Illuminate\Http\Response;

final class ShowPricingController
{
    /**
     * Show every service's tiers, add-ons and meters straight from the billing catalogue, cached publicly for five
     * minutes.
     *
     * @param  ServiceRegistry  $services
     * @return Response
     */
    public function __invoke(ServiceRegistry $services): Response
    {
        return response()->view('site.pricing', ['services' => $services->all()])->header('Cache-Control', 'public, max-age=300');
    }
}
