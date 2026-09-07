<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmailReadinessNotification extends Notification
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
     * Compose the diagnostic delivery message used to verify the configured email transport.
     *
     * @param  object  $notifiable  Notification recipient whose delivery routing is resolved by Laravel.
     * @return MailMessage The composed email message for Laravel to deliver to the recipient.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('BuildPusher email delivery test')
            ->greeting('BuildPusher email is connected')
            ->line('This message confirms that the configured production mail transport accepted a test notification.')
            ->line('Verify that it arrived in the inbox, is not marked as spam, and passes SPF, DKIM, and DMARC checks.');
    }
}
