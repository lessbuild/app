<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccessInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $url, public readonly int $days) {}

    public function via(object $notifiable): array { return ['mail']; }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject(__('Your BuildPusher invitation'))
            ->greeting(__('You are invited to BuildPusher'))
            ->line(__('Your access request has been approved. Create your account using the secure link below.'))
            ->action(__('Create account'), $this->url)
            ->line(trans_choice('This invitation expires in :count day.|This invitation expires in :count days.', $this->days, ['count' => $this->days]))
            ->line(__('If you did not request access, you can ignore this message.'));
    }
}
