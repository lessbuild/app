<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrganizationInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly string $organization, public readonly string $url) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('You are invited to :organization on BuildPusher', ['organization' => $this->organization]))
            ->line(__('You have been invited to collaborate in the :organization workspace.', ['organization' => $this->organization]))
            ->action(__('Accept invitation'), $this->url)
            ->line(__('This invitation expires in seven days.'));
    }
}
