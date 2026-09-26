<?php

namespace App\Core\Http\Controllers;

use App\Core\Http\Requests\StoreCustomerStatusSubscriptionRequest;
use App\Core\Services\CustomerStatusPageProviderRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;

final class CoreCustomerStatusPageController
{
    public function __invoke(CustomerStatusPageProviderRegistry $providers, string $product, string $slug): Response
    {
        $statusPage = $providers->findPublished($product, $slug);
        abort_if($statusPage === null, 404);

        return response()->view('core::status-pages.show', [
            'statusPage' => $statusPage,
            'canonical' => route('core.status-pages.show', ['product' => $product, 'slug' => $slug]),
            'indexable' => false,
            'subscriptionAction' => $product === 'deployer'
                ? route('core.status-pages.subscribe', ['product' => $product, 'slug' => $slug])
                : null,
            'reportUrl' => $product === 'deployer' && Route::has('status.report')
                ? route('status.report', $slug)
                : null,
        ])->header('Cache-Control', $product === 'deployer' ? 'no-store, private' : 'public, max-age=30, stale-while-revalidate=60')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    public function subscribe(
        StoreCustomerStatusSubscriptionRequest $request,
        CustomerStatusPageProviderRegistry $providers,
        string $product,
        string $slug,
    ): RedirectResponse {
        abort_unless($providers->subscribe($product, $slug, $request->email()), 404);

        return to_route('core.status-pages.show', ['product' => $product, 'slug' => $slug])
            ->with('status_subscription', __('Check your email to confirm status updates.'));
    }
}
