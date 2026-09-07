<?php

namespace App\Http\Controllers;

use App\Models\Provider;
use App\Services\ProviderHealthMonitor;
use Illuminate\Http\RedirectResponse;

class ProviderConnectionController extends Controller
{
    /**
     * Check an editable provider connection and redirect to its page with the health result.
     */
    public function __invoke(Provider $provider, ProviderHealthMonitor $monitor): RedirectResponse
    {
        $this->authorize('update', $provider);

        $result = $monitor->check($provider);

        return redirect()
            ->route('providers.show', $provider)
            ->with('provider_connection', [
                'successful' => $result['successful'],
                'message' => $result['message'],
            ]);
    }
}
