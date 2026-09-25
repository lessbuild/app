<?php

namespace App\Core\Http\Controllers;

use App\Core\Models\PlatformUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ThemePreferenceController
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'appearance' => ['required', 'string', 'in:system,light,dark'],
        ]);
        $user = $request->user('platform');
        abort_unless($user instanceof PlatformUser && $user->status === 'active', 401);

        DB::connection('core')->transaction(function () use ($user, $validated): void {
            $account = PlatformUser::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $preferences = $account->preferences ?? [];
            $preferences['theme'] = $validated['appearance'];

            $account->forceFill(['preferences' => $preferences])->save();
        });

        return response()->json(['appearance' => $validated['appearance']]);
    }
}
