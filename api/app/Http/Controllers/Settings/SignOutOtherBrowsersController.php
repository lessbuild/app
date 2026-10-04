<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Users\SignOutBrowsers;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SignOutOtherBrowsersController
{
    /**
     * Sign out every browser except this one.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  SignOutBrowsers  $signOut
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, SignOutBrowsers $signOut): JsonResponse
    {
        $signOut->handle($user, $request->session()->getId());

        return response()->json(['redirect' => route('settings.sessions', [], false), 'message' => __('All other browsers are signed out.')]);
    }
}
