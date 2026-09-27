<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** A message for someone's inbox: a title, one line of detail and where to go. */
abstract class InboxNotification extends Notification
{
    use Queueable;

    abstract protected function title(): string;

    abstract protected function body(): string;

    abstract protected function url(): string;

    protected function accountId(): ?string
    {
        return null;
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array{title: string, body: string, url: string, account_id: string|null} */
    public function toArray(object $notifiable): array
    {
        return ['title' => $this->title(), 'body' => $this->body(), 'url' => $this->url(), 'account_id' => $this->accountId()];
    }
}
