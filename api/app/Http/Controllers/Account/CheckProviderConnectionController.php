<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Models\Provider;
use App\Models\User;
use App\Services\Infrastructure\ProviderHealthMonitor;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class CheckProviderConnectionController
{
    /**
     * Check the provider's stored credential now and shows the result.
     *
     * @param  User  $user
     * @param  Provider  $provider
     * @param  ProviderHealthMonitor  $monitor
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, Provider $provider, ProviderHealthMonitor $monitor): RedirectResponse
    {
        $result = $monitor->check($provider);

        return to_route('account.providers.show', $provider->id)->with($result['successful'] ? 'status' : 'error', $result['message']);
    }
}
