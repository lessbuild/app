<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewAccessRequestNotification extends Notification implements ShouldQueue
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
     * Compose the administrator notice linking to pending access requests without embedding applicant details.
     *
     * @param  object  $notifiable  Notification recipient whose delivery routing is resolved by Laravel.
     * @return MailMessage The composed email message for Laravel to deliver to the recipient.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject(__('New BuildPusher access request'))
            ->line(__('A new encrypted access request is ready for platform review.'))
            ->action(__('Review request'), route('admin.access-requests.index', ['status' => 'pending']))
            ->line(__('Applicant details are intentionally available only inside the authenticated admin area.'));
    }
}
