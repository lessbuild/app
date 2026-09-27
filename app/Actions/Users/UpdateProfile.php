<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Data\Users\UpdateProfileData;
use App\Events\Users\ProfileUpdated;
use App\Models\User;
use Illuminate\Support\Str;

final class UpdateProfile
{
    /** A changed email address must be verified again before it is trusted. */
    public function handle(User $user, UpdateProfileData $data): void
    {
        $email = Str::lower(trim($data->email));
        $emailChanged = $email !== $user->email;

        $user->forceFill([
            'name' => $data->name,
            'email' => $email,
            'email_verified_at' => $emailChanged ? null : $user->email_verified_at,
        ])->save();

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }

        ProfileUpdated::dispatch($user);
    }
}
