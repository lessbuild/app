<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use Illuminate\Contracts\View\View;

final class ShowSsoLoginController
{
    /**
     * Show the single sign-on form, which asks for a work email address.
     *
     * @return View
     */
    public function __invoke(): View
    {
        return view('auth.sso');
    }
}
