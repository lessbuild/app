<?php

namespace App\Notifications;

use App\Models\StatusSubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ConfirmStatusSubscriptionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Capture the pending status subscription and its plaintext confirmation token.
     *
     * @param  StatusSubscription  $subscription  Pending subscription identifying the public status page.
     * @param  string  $token  Plaintext confirmation token included only in the confirmation link.
     */
    public function __construct(private readonly StatusSubscription $subscription, private readonly string $token) {}

    /**
     * Deliver this notification through Laravel's mail channel.
     *
     * @param  object  $notifiable  Notification recipient whose delivery routing is resolved by Laravel.
     * @return list<string> The single mail channel used for this notification.
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Compose the status-page subscription confirmation link using the supplied token.
     *
     * @param  object  $notifiable  Notification recipient whose delivery routing is resolved by Laravel.
     * @return MailMessage The composed email message for Laravel to deliver to the recipient.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Confirm :name status updates', ['name' => $this->subscription->statusPage->name]))
            ->line(__('Confirm your address to receive incident and maintenance updates.'))
            ->action(__('Confirm status updates'), route('status.subscriptions.confirm', [$this->subscription, $this->token]));
    }
}
