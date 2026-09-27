<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\StatusSubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

/** Asks someone who subscribed on a public status page to confirm their address. */
final class StatusSubscriptionConfirmation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly StatusSubscription $subscription,
        #[SensitiveParameter] public readonly string $token,
    ) {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Confirm :page status updates', ['page' => $this->subscription->statusPage->name]))
            ->line(__('Confirm your address to get an email when :page posts an incident or maintenance update.', ['page' => $this->subscription->statusPage->name]))
            ->action(__('Confirm status updates'), route('status.subscriptions.confirm', [$this->subscription->id, $this->token]))
            ->line(__('If you didn’t ask for this, ignore this email and you won’t hear from us.'));
    }
}
