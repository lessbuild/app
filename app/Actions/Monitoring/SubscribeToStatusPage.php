<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Models\StatusPage;
use App\Models\StatusSubscription;
use App\Notifications\StatusSubscriptionConfirmation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use LogicException;

final class SubscribeToStatusPage
{
    /**
     * Start (or restart) an email subscription and send the confirmation link. Nothing is sent to the address until it's confirmed.
     */
    public function handle(StatusPage $page, string $email): StatusSubscription
    {
        $email = mb_strtolower(trim($email));
        $token = Str::random(64);
        $hash = StatusSubscription::hashEmail($email);
        $attributes = ['email' => $email, 'verification_token_hash' => hash('sha256', $token), 'unsubscribe_token' => Str::random(64), 'verified_at' => null];
        $existing = fn (): ?StatusSubscription => StatusSubscription::query()->where('status_page_id', $page->id)->where('email_hash', $hash)->first();
        $subscription = $existing() ?? (new StatusSubscription)->forceFill(['status_page_id' => $page->id, 'email_hash' => $hash]);
        try {
            $subscription->forceFill($attributes)->save();
        } catch (UniqueConstraintViolationException) {
            // Someone subscribed the same address a moment ago: restart that subscription instead.
            $subscription = $existing() ?? throw new LogicException('The subscription just existed.');
            $subscription->forceFill($attributes)->save();
        }

        Notification::route('mail', $email)->notify(new StatusSubscriptionConfirmation($subscription, $token));

        return $subscription;
    }
}
