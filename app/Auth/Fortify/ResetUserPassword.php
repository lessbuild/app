<?php

declare(strict_types=1);

namespace App\Auth\Fortify;

use App\Domain\Identity\Actions\ChangePassword;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

final class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    public function __construct(private readonly ChangePassword $changePassword) {}

    /** @param array<string, string> $input */
    public function reset(User $user, array $input): void
    {
        $validated = Validator::make($input, ['password' => $this->passwordRules()])->validate();

        $this->changePassword->handle($user, $validated['password']);
    }
}
