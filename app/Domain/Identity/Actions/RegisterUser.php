<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Accounts\Actions\CreateAccount;
use App\Domain\Identity\Data\RegisterUserData;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RegisterUser
{
    public function __construct(private readonly CreateAccount $createAccount) {}

    /** Create the user together with a first account they own, so every signed-in user has somewhere to work. */
    public function handle(RegisterUserData $data): User
    {
        return DB::transaction(function () use ($data): User {
            $user = new User;
            $user->forceFill([
                'name' => $data->name,
                'email' => Str::lower(trim($data->email)),
                'password' => $data->password,
            ])->save();

            $this->createAccount->handle($user, __(':name’s account', ['name' => Str::before($data->name, ' ') ?: $data->name]));

            return $user->refresh();
        });
    }
}
