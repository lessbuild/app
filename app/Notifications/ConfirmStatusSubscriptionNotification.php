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

    public function __construct(private readonly StatusSubscription $subscription, private readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Confirm :name status updates', ['name' => $this->subscription->statusPage->name]))
            ->line(__('Confirm your address to receive incident and maintenance updates.'))
            ->action(__('Confirm status updates'), route('status.subscriptions.confirm', [$this->subscription, $this->token]));
    }
}
