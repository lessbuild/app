<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Events\Users\PasswordChanged;
use App\Models\User;
use Illuminate\Support\Str;
use SensitiveParameter;

final class ChangePassword
{
    /**
     * Set a new password and rotates the remember token, which signs out "remember me" cookies on other devices.
     *
     * @param  User  $user
     * @param  string  $password
     * @return void
     */
    public function handle(User $user, #[SensitiveParameter] string $password): void
    {
        // Rotating the remember token signs out "remember me" cookies on other devices.
        $user->forceFill(['password' => $password])->setRememberToken(Str::random(60));
        $user->save();

        PasswordChanged::dispatch($user);
    }
}
