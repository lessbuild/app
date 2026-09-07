<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccessRequestReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

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
     * Compose an acknowledgement that the access request awaits review.
     *
     * @param  object  $notifiable  Notification recipient whose delivery routing is resolved by Laravel.
     * @return MailMessage The composed email message for Laravel to deliver to the recipient.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject(__('We received your BuildPusher access request'))
            ->greeting(__('Thanks for your interest in BuildPusher'))
            ->line(__('We received your request and will review the deployment workflow you described.'))
            ->line(__('We will contact you at this address if BuildPusher is a fit. No payment details or cloud credentials are required during review.'));
    }
}
