<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Notifications;

use App\Domain\Accounts\Models\AccountInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

final class AccountInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly AccountInvitation $invitation,
        #[SensitiveParameter] public readonly string $token,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $account = $this->invitation->account;

        return (new MailMessage)
            ->subject(__('You’re invited to :account on :app', ['account' => $account->name, 'app' => config('app.name')]))
            ->line(__(':inviter invited you to join :account as :role.', [
                'inviter' => $this->invitation->invitedBy->name ?? __('A teammate'),
                'account' => $account->name,
                'role' => $this->invitation->role->label(),
            ]))
            ->action(__('Accept invitation'), route('invitations.show', $this->token))
            ->line(__('This invitation expires :date.', ['date' => $this->invitation->expires_at->toFormattedDayDateString()]));
    }
}
