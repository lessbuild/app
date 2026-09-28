<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Models\User;
use App\Queries\Accounts\DepartureQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowPrivacyController
{
    /**
     * The privacy page: data export, and what deleting the person would do to their accounts.
     *
     * @param  User  $user
     * @param  DepartureQuery  $departure
     * @return View
     */
    public function __invoke(#[CurrentUser] User $user, DepartureQuery $departure): View
    {
        return view('settings.privacy', ['user' => $user, 'departure' => $departure->handle($user)]);
    }
}
