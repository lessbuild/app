<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Models\SignInEvent;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `DELETE /api/app/settings/sign-ins`. */
final class ClearSignInsController
{
    /**
     * Forget the person's sign-in history. Sessions that are still signed in stay signed in.
     *
     * @param  User  $user
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user): JsonResponse
    {
        SignInEvent::query()->where('user_id', $user->id)->delete();

        return response()->json(['message' => __('Sign-in history cleared.')]);
    }
}
