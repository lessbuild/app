<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewAccessRequestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject(__('New BuildPusher access request'))
            ->line(__('A new encrypted access request is ready for platform review.'))
            ->action(__('Review request'), route('admin.access-requests.index', ['status' => 'pending']))
            ->line(__('Applicant details are intentionally available only inside the authenticated admin area.'));
    }
}
