<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells someone who asked for access that the request arrived. */
final class AccessRequestReceived extends Notification
{
    use Queueable;

    /**
     * Get the delivery channels: email only, since they have no account yet.
     *
     * @param  object  $notifiable
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Build the receipt.
     *
     * @param  object  $notifiable
     * @return MailMessage
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject(__('We got your request for :app', ['app' => config('app.name')]))
            ->line(__('Thanks for asking. We read every request and will email you an invitation when we can let you in.'));
    }
}
