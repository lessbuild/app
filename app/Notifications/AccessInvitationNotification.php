<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccessInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Capture the invitation acceptance URL and validity period.
     *
     * @param  string  $url  Invitation acceptance URL delivered to the approved applicant.
     * @param  int  $days  Number of days for which the invitation remains valid.
     */
    public function __construct(public readonly string $url, public readonly int $days) {}

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
     * Compose an access invitation with its acceptance link and expiry period.
     *
     * @param  object  $notifiable  Notification recipient whose delivery routing is resolved by Laravel.
     * @return MailMessage The composed email message for Laravel to deliver to the recipient.
     */
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
