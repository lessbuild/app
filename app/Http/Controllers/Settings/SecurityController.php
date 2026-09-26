<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Queries\SecuritySettingsQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Laravel\Fortify\Fortify;

final class SecurityController
{
    public function __invoke(Request $request, #[CurrentUser] User $user, SecuritySettingsQuery $query): View
    {
        // Fortify flashes these statuses right after codes are created; that is the only time they are shown.
        $reveal = in_array($request->session()->get('status'), [Fortify::TWO_FACTOR_AUTHENTICATION_CONFIRMED, Fortify::RECOVERY_CODES_GENERATED], true);

        return view('settings.security', [
            'user' => $user,
            'security' => $query->handle($user, $reveal),
        ]);
    }
}
