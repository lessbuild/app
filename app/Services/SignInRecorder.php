<?php

namespace App\Services;

use App\Enums\SignInMethod;
use App\Models\SignInEvent;
use App\Models\User;
use App\Notifications\AccountSecurityNotification;
use Illuminate\Http\Request;
use Throwable;

class SignInRecorder
{
    public function __construct(private readonly ClientMetadata $clients) {}

    /**
     * Record a recognized authentication method and report new-device activity.
     *
     * @param  User  $user  The authenticated account.
     * @param  SignInMethod|string  $method  A supported method or its persisted value.
     * @param  Request  $request  The request providing client metadata.
     * @return SignInEvent|null The recorded event, or null when recording fails.
     *
     * @throws \InvalidArgumentException When the method is unsupported.
     */
    public function record(User $user, SignInMethod|string $method, Request $request): ?SignInEvent
    {
        $method = $method instanceof SignInMethod ? $method : SignInMethod::tryFrom($method);
        if ($method === null) {
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
                'method' => $method->value,
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
