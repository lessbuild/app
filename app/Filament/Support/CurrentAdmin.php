<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;

/** The platform admin using the panel, for Actions that record who did what. */
final class CurrentAdmin
{
    /**
     * Get the signed-in admin. The panel's middleware has already made sure there is one.
     *
     * @return User
     *
     * @throws AuthenticationException when nobody is signed in
     */
    public static function user(): User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : throw new AuthenticationException;
    }
}
