<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Events\PasswordChanged;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Str;
use SensitiveParameter;

final class ChangePassword
{
    public function handle(User $user, #[SensitiveParameter] string $password): void
    {
        // Rotating the remember token signs out "remember me" cookies on other devices.
        $user->forceFill(['password' => $password])->setRememberToken(Str::random(60));
        $user->save();

        PasswordChanged::dispatch($user);
    }
}
