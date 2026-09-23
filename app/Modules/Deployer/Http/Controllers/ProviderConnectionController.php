<?php

namespace App\Modules\Deployer\Http\Controllers;

use App\Modules\Deployer\Models\Provider;
use App\Modules\Deployer\Services\ProviderHealthMonitor;
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
