<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Domain\Identity\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ProfileController
{
    public function __invoke(#[CurrentUser] User $user): View
    {
        return view('settings.profile', ['user' => $user]);
    }
}
