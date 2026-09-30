<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Users\AddPushDevice;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StorePushDeviceController
{
    /**
     * Save this browser's push subscription (sent by the settings page's script).
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  AddPushDevice  $add
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, AddPushDevice $add): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2000'],
            'keys.p256dh' => ['required', 'string', 'max:200'],
            'keys.auth' => ['required', 'string', 'max:100'],
            'device' => ['nullable', 'string', 'max:120'],
        ]);
        $subscription = $add->handle($user, $data['endpoint'], $data['keys']['p256dh'], $data['keys']['auth'], $data['device'] ?? null);

        return response()->json(['id' => $subscription->id], 201);
    }
}
