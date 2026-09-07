<?php

namespace App\Http\Controllers;

use App\Models\Provider;
use App\Services\ServerCatalog;
use App\Services\ServerProviderResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class ProviderServerCatalogController extends Controller
{
    /**
     * Require deployment access to a current-workspace server provider and fetch its provisioning catalog.
     *
     * @return JsonResponse Catalog options, or HTTP 502 when the provider cannot be queried.
     */
    public function __invoke(
        Request $request,
        Provider $provider,
        ServerProviderResolver $resolver,
        ServerCatalog $catalog,
    ): JsonResponse {
        abort_unless(
            $provider->organization_id === $request->user()->current_organization_id
                && ($provider->organization?->permits($request->user(), 'deploy') ?? false)
                && in_array($provider->provider, Provider::SERVER_TYPES, true),
            403,
        );

        try {
            return response()->json($catalog->for($provider, $resolver->resolve($provider)));
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => __('The provider catalog could not be loaded. Check the credential and try again.'),
            ], 502);
        }
    }
}
