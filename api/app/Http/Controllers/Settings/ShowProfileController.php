<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowProfileController
{
    /**
     * Show the profile page.
     *
     * @param  User  $user
     * @return View
     */
    public function __invoke(#[CurrentUser] User $user): View
    {
        return view('settings.profile', ['user' => $user]);
    }
}
