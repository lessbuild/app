<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\SignInMethod;
use App\Models\SignInEvent;
use App\Models\User;
use Illuminate\Support\Str;

final class RecordSignIn
{
    /**
     * Stores one sign-in attempt for the person's activity list, with the user agent cut to 500 characters.
     */
    public function handle(User $user, bool $succeeded, ?SignInMethod $method, bool $twoFactor, ?string $ipAddress, ?string $userAgent): void
    {
        $event = new SignInEvent;
        $event->forceFill([
            'user_id' => $user->id,
            'succeeded' => $succeeded,
            'method' => $method,
            'two_factor' => $twoFactor,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent !== null ? Str::limit($userAgent, 500, '') : null,
        ])->save();
    }
}
