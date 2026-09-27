<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Actions\Users\ChangePassword;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

final class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Fortify's password-reset adapter.
     *
     * @param  ChangePassword  $changePassword  Sets the new password.
     */
    public function __construct(private readonly ChangePassword $changePassword) {}

    /**
     * Validates the new password from a reset link and sets it.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        $validated = Validator::make($input, ['password' => $this->passwordRules()])->validate();

        $this->changePassword->handle($user, $validated['password']);
    }
}
