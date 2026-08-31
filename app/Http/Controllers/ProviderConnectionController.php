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

        return redirect()
            ->route('providers.show', $provider)
            ->with('provider_connection', $tester->test($provider));
    }
}
