<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Users\SignOutBrowsers;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class SignOutBrowserController
{
    /**
     * Signs out one of the person's other browsers.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  SignOutBrowsers  $signOut
     * @param  string  $session
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, SignOutBrowsers $signOut, string $session): RedirectResponse
    {
        $count = $signOut->handle($user, $request->session()->getId(), $session);

        return to_route('settings.sessions')->with('status', $count > 0 ? 'browser-signed-out' : 'browser-not-found');
    }
}
