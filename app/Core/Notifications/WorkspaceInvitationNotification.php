<?php

namespace App\Core\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class WorkspaceInvitationNotification extends Notification
{
    public function __construct(
        private readonly string $workspaceName,
        private readonly string $inviterName,
        private readonly string $role,
        private readonly string $token,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('platform.workspace-invitations.show', ['token' => $this->token]);

        return (new MailMessage)
            ->subject(__('Invitation to :workspace', ['workspace' => $this->workspaceName]))
            ->greeting(__('You have been invited to Buildpusher'))
            ->line(__(':name invited you to join the :workspace workspace as :role.', [
                'name' => $this->inviterName,
                'workspace' => $this->workspaceName,
                'role' => str($this->role)->headline(),
            ]))
            ->action(__('Review invitation'), $url)
            ->line(__('The invitation expires in :days days. Product access and subscriptions are granted separately.', [
                'days' => (int) config('lessbuild.registration.invitation_days', 7),
            ]));
    }
}
