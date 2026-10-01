<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Data\Users\UpdateProfileData;
use App\Events\Users\ProfileUpdated;
use App\Models\User;
use Illuminate\Support\Str;

final class UpdateProfile
{
    /**
     * Save the person's name, email and language. A changed email address must be verified again before it is trusted.
     *
     * @param  User  $user
     * @param  UpdateProfileData  $data
     * @return void
     */
    public function handle(User $user, UpdateProfileData $data): void
    {
        $email = Str::lower(trim($data->email));
        $emailChanged = $email !== $user->email;

        $user->forceFill([
            'name' => $data->name,
            'email' => $email,
            'locale' => $data->locale,
            'email_verified_at' => $emailChanged ? null : $user->email_verified_at,
        ])->save();

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }

        ProfileUpdated::dispatch($user);
    }
}
