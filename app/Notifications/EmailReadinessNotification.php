<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmailReadinessNotification extends Notification
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('BuildPusher email delivery test')
            ->greeting('BuildPusher email is connected')
            ->line('This message confirms that the configured production mail transport accepted a test notification.')
            ->line('Verify that it arrived in the inbox, is not marked as spam, and passes SPF, DKIM, and DMARC checks.');
    }
}
