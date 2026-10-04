<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `GET /api/app/settings/profile`. */
final class ShowProfileController
{
    /**
     * Return the person's name, email address and language, and the languages they can choose.
     *
     * @param  User  $user
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user): JsonResponse
    {
        return response()->json([
            'name' => $user->name,
            'email' => $user->email,
            'locale' => $user->locale,
            'locales' => config('app.supported_locales'),
        ]);
    }
}
