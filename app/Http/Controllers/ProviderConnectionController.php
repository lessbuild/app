<?php

namespace App\Http\Controllers;

use App\Models\Provider;
use App\Services\ProviderConnectionTester;
use Illuminate\Http\RedirectResponse;

class ProviderConnectionController extends Controller
{
    public function __invoke(Provider $provider, ProviderConnectionTester $tester): RedirectResponse
    {
        $this->authorize('update', $provider);

        $providerType = $provider->provider;
        $encryptedToken = (string) $provider->getRawOriginal('token');
        $result = $tester->test($provider);

        if (! $provider->recordConnectionResult($result['successful'], $providerType, $encryptedToken)) {
            $result = [
                'successful' => false,
                'message' => __('The provider credential changed during this check. Run it again to verify the new credential.'),
            ];
        }

        return redirect()
            ->route('providers.show', $provider)
            ->with('provider_connection', $result);
    }
}
