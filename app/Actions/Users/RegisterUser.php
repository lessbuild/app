<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Actions\Accounts\CreateAccount;
use App\Data\Users\RegisterUserData;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RegisterUser
{
    /**
     * Create a new RegisterUser instance.
     *
     * Registration gives every new person an account of their own.
     *
     * @param  CreateAccount  $createAccount  Creates that first account with them as owner.
     */
    public function __construct(private readonly CreateAccount $createAccount) {}

    /**
     * Create the user together with a first account they own, so every signed-in user has somewhere to work.
     *
     * @param  RegisterUserData  $data
     * @return User
     */
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
