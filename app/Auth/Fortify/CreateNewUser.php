<?php

declare(strict_types=1);

namespace App\Auth\Fortify;

use App\Domain\Identity\Actions\RegisterUser;
use App\Domain\Identity\Data\RegisterUserData;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/** Fortify adapter: validates registration input and delegates to the Identity domain. */
final class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    public function __construct(private readonly RegisterUser $registerUser) {}

    /** @param array<string, string> $input */
    public function create(array $input): User
    {
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)],
            'password' => $this->passwordRules(),
        ])->validate();

        return $this->registerUser->handle(new RegisterUserData($validated['name'], $validated['email'], $validated['password']));
    }
}
