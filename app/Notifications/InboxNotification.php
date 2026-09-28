<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A message for someone's inbox: a title, one line of detail and where to go. */
abstract class InboxNotification extends Notification
{
    use Queueable;

    /**
     * The headline shown in the inbox.
     *
     * @return string
     */
    abstract protected function title(): string;

    /**
     * One line of detail under the headline.
     *
     * @return string
     */
    abstract protected function body(): string;

    /**
     * Where clicking the notification goes.
     *
     * @return string
     */
    abstract protected function url(): string;

    /**
     * The account the notification is about, so the inbox can switch to it; null for notifications that aren't about
     * one.
     *
     * @return string|null
     */
    protected function accountId(): ?string
    {
        return null;
    }

    /**
     * Inbox notifications are stored in the database by default; subclasses add mail when it matters outside the app.
     *
     * @param  object  $notifiable
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * The email version for notifications that are also mailed: the title as the subject, the body, and a button to
     * the same page the inbox links to.
     *
     * @param  string  $action
     * @return MailMessage
     */
    protected function mailWithAction(string $action): MailMessage
    {
        return (new MailMessage)->subject($this->title())->line($this->body())->action($action, $this->url());
    }

    /**
     * What's stored for the inbox.
     *
     * @param  object  $notifiable
     * @return array{title: string, body: string, url: string, account_id: string|null}
     */
    public function toArray(object $notifiable): array
    {
        return ['title' => $this->title(), 'body' => $this->body(), 'url' => $this->url(), 'account_id' => $this->accountId()];
    }
}
