<?php

namespace App\Modules\Monitor\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkspaceInvitationNotification extends Notification
{
    public function __construct(public readonly string $token) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your '.config('app.name').' workspace invitation')
            ->line('You have been invited to a '.config('app.name').' workspace.')
            ->line('Sign in or create an account using this email address, then verify your email to join.')
            ->action('Review invitation', route('monitor.invitations.show', $this->token))
            ->line('This invitation expires in seven days. Ignore it if you were not expecting an invitation.');
    }
}
