<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AccountInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

final class AccountInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The email inviting someone to join an account. Queued, since sending mail shouldn't hold up the invite form.
     *
     * @param  AccountInvitation  $invitation  The invitation being sent.
     * @param  string  $token  The plain invitation token for the link. Only its hash is stored, so this email is the one
     *                         place it exists.
     */
    public function __construct(
        public readonly AccountInvitation $invitation,
        #[SensitiveParameter] public readonly string $token,
    ) {}

    /**
     * Invitations go by email only: the invitee may not have a user here yet.
     *
     * @param  object  $notifiable
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Says who invited them, to which account and role, links to the invitation page and says when it expires.
     *
     * @param  object  $notifiable
     * @return MailMessage
     */
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
