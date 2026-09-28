<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Users\SignOutBrowsers;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class SignOutOtherBrowsersController
{
    /**
     * Signs out every browser except this one.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  SignOutBrowsers  $signOut
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, SignOutBrowsers $signOut): RedirectResponse
    {
        $signOut->handle($user, $request->session()->getId());

        return to_route('settings.sessions')->with('status', 'other-browsers-signed-out');
    }
}
