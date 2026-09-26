<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\SignInMethod;
use App\Domain\Identity\Models\SignInEvent;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Str;

final class RecordSignIn
{
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
