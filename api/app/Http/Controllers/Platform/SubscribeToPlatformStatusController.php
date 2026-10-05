<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Models\PlatformStatusSubscriber;
use App\Notifications\PlatformStatusSubscriptionConfirmation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

final class SubscribeToPlatformStatusController
{
    /**
     * Subscribe an address to BuildPusher's status emails, once it's confirmed. The answer is the same whether or not
     * the address was already subscribed, so it says nothing about who is.
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function __invoke(Request $request): JsonResponse
    {
        $email = (string) $request->validate(['email' => ['required', 'string', 'email:rfc', 'max:255']])['email'];
        $subscriber = PlatformStatusSubscriber::query()->firstWhere('email_hash', PlatformStatusSubscriber::hashEmail($email));
        if ($subscriber?->verified_at === null) {
            $token = Str::random(48);
            $subscriber ??= new PlatformStatusSubscriber;
            $subscriber->forceFill([
                'email' => mb_strtolower(trim($email)),
                'email_hash' => PlatformStatusSubscriber::hashEmail($email),
                'verification_token_hash' => hash('sha256', $token),
                'unsubscribe_token' => $subscriber->exists ? $subscriber->unsubscribe_token : Str::random(48),
            ])->save();
            Notification::route('mail', $subscriber->email)->notify(new PlatformStatusSubscriptionConfirmation($subscriber, $token));
        }

        return response()->json(['message' => __('Check your inbox to confirm your address.')]);
    }
}
