<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Validation\Rules\Password;

trait PasswordValidationRules
{
    /**
     * Get the rules every new password must pass: the app's default password policy, confirmed.
     *
     * @return array<int, Rule|array<mixed>|string|Password>
     */
    protected function passwordRules(): array
    {
        return ['required', 'string', Password::default(), 'confirmed'];
    }
}
