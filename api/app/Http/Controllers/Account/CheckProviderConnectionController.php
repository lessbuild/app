<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Models\Provider;
use App\Models\User;
use App\Services\Infrastructure\ProviderHealthMonitor;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class CheckProviderConnectionController
{
    /**
     * Check the provider's stored credential now and shows the result.
     *
     * @param  User  $user
     * @param  Provider  $provider
     * @param  ProviderHealthMonitor  $monitor
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Provider $provider, ProviderHealthMonitor $monitor): JsonResponse
    {
        $result = $monitor->check($provider);

        return response()->json(['successful' => $result['successful'], 'message' => $result['message']]);
    }
}
