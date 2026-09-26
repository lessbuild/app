<?php

namespace App\Modules\Analytics\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkspaceInvitation extends Notification
{
    use Queueable;

    public function __construct(private readonly string $token) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('You have been invited to Buildpusher Analytics')
            ->greeting('You have a Buildpusher Analytics invitation')
            ->line('Join a workspace to collaborate on website analytics.')
            ->action('View invitation', route('analytics.invitations.show', ['token' => $this->token]))
            ->line('This invitation expires in '.config('analytics.invitation_expiry_days').' days.');
    }
}
