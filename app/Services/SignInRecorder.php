<?php

namespace App\Services;

use App\Models\SignInEvent;
use App\Models\User;
use App\Notifications\AccountSecurityNotification;
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
            $ip = $this->clients->normalizedIp($request->ip());
            $agent = $this->clients->normalizedUserAgent($request->userAgent());
            $hasHistory = $user->signIns()->exists();
            $recognized = $hasHistory && $user->signIns()
                ->where('signed_in_at', '>=', now()->subDays(90))
                ->where('ip_address', $ip)
                ->where('user_agent', $agent)
                ->exists();
            $event = $user->signIns()->create([
                'method' => $method,
                'ip_address' => $ip,
                'user_agent' => $agent,
                'signed_in_at' => now(),
            ]);
            if ($hasHistory && ! $recognized) {
                $user->notify(new AccountSecurityNotification('A sign-in from a new device or network was recorded. Review sign-in history if this was not you.'));
            }

            return $event;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
