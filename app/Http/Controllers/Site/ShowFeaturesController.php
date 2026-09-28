<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Platform\ServiceRegistry;
use Illuminate\Http\Response;

final class ShowFeaturesController
{
    /**
     * Show a service's public page (what it does, how it works with the others, and common questions), cached publicly for five minutes.
     *
     * @param  string  $service
     * @param  ServiceRegistry  $services
     * @return Response
     */
    public function __invoke(string $service, ServiceRegistry $services): Response
    {
        $definition = $services->find($service);
        $copy = config('marketing.services.'.$service);
        abort_if($definition === null || ! is_array($copy), 404);

        return response()->view('site.features', ['service' => $definition, 'copy' => $copy, 'services' => $services->all()])->header('Cache-Control', 'public, max-age=300');
    }
}
