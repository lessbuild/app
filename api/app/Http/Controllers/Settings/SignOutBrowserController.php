<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Users\SignOutBrowsers;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SignOutBrowserController
{
    /**
     * Sign out one of the person's other browsers.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  SignOutBrowsers  $signOut
     * @param  string  $session
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, SignOutBrowsers $signOut, string $session): JsonResponse
    {
        $count = $signOut->handle($user, $request->session()->getId(), $session);

        return response()->json(['redirect' => route('settings.sessions', [], false), 'message' => $count > 0 ? __('That browser is signed out.') : __('That browser was already signed out.')]);
    }
}
