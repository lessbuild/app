<?php

namespace App\Actions\Account;

use App\Data\ResetPasswordData;
use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Str;

class ResetPasswordAction
{
    public function __construct(
        private readonly PasswordBroker $passwords,
        private readonly Hasher $hasher,
        private readonly ActivityRecorder $activity,
        private readonly Dispatcher $events,
    ) {}

    /**
     * Validate a reset token through Laravel's broker and persist the replacement local password.
     *
     * The broker status is returned unchanged so the controller can preserve its public success/failure mapping.
     */
    public function handle(ResetPasswordData $data): string
    {
        return $this->passwords->reset([
            'email' => $data->email,
            'password' => $data->password,
            'token' => $data->token,
        ], function (User $user) use ($data): void {
            $user->forceFill([
                'password' => $this->hasher->make($data->password),
                'password_set_at' => now(),
                'remember_token' => Str::random(60),
            ])->save();

            $this->activity->recordAccount($user, 'Account password was reset.');
            $this->events->dispatch(new PasswordReset($user));
        });
    }
}
