<?php

namespace App\Modules\Monitor\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class IssueDigestNotification extends Notification
{
    /** @param array<string, mixed> $digest */
    public function __construct(public readonly array $digest) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(config('app.name').' issue digest · '.$this->digest['workspace_name'])
            ->markdown('mail.issue-digest', ['digest' => $this->digest]);
    }
}
