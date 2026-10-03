<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Platform\ServiceRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class ShowHomeController
{
    /**
     * Show the public home page to guests, cached publicly for five minutes; send signed-in people to their dashboard.
     *
     * @param  Request  $request
     * @param  ServiceRegistry  $services
     * @return Response|RedirectResponse
     */
    public function __invoke(Request $request, ServiceRegistry $services): Response|RedirectResponse
    {
        if ($request->user() !== null) {
            return to_route('dashboard');
        }

        return response()->view('site.home', ['services' => $services->all()])->header('Cache-Control', 'public, max-age=300');
    }
}
