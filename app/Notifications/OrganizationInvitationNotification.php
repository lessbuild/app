<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrganizationInvitationNotification extends Notification
{
    use Queueable;

    /**
     * Capture the workspace display name and invitation acceptance URL.
     *
     * @param  string  $organization  Workspace name displayed in the invitation.
     * @param  string  $url  Invitation acceptance URL for the intended workspace member.
     */
    public function __construct(public readonly string $organization, public readonly string $url) {}

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
     * Compose the workspace invitation and its seven-day acceptance notice.
     *
     * @param  object  $notifiable  Notification recipient whose delivery routing is resolved by Laravel.
     * @return MailMessage The composed email message for Laravel to deliver to the recipient.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('You are invited to :organization on BuildPusher', ['organization' => $this->organization]))
            ->line(__('You have been invited to collaborate in the :organization workspace.', ['organization' => $this->organization]))
            ->action(__('Accept invitation'), $this->url)
            ->line(__('This invitation expires in seven days.'));
    }
}
