<?php

namespace App\Services;

use App\Models\SignInEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Throwable;

class SignInRecorder
{
    public function __construct(private readonly ClientMetadata $clients) {}

    public function record(User $user, string $method, Request $request): ?SignInEvent
    {
        if (! in_array($method, SignInEvent::METHODS, true)) {
            throw new \InvalidArgumentException('Unsupported sign-in method.');
        }

        try {
            return $user->signIns()->create([
                'method' => $method,
                'ip_address' => $this->clients->normalizedIp($request->ip()),
                'user_agent' => $this->clients->normalizedUserAgent($request->userAgent()),
                'signed_in_at' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
