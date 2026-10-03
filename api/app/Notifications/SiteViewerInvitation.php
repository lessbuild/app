<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Gives someone their personal link to a site's analytics report. */
final class SiteViewerInvitation extends Notification
{
    /**
     * Create a new SiteViewerInvitation instance.
     *
     * @param  string  $site  The site's name.
     * @param  string  $inviter  Who invited them.
     * @param  string  $url  Their personal link.
     */
    public function __construct(public readonly string $site, public readonly string $inviter, public readonly string $url) {}

    /**
     * Get the notification's delivery channels: email only.
     *
     * @param  object  $notifiable
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Build the email with the link.
     *
     * @param  object  $notifiable
     * @return MailMessage
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__(':inviter shared :site’s analytics with you', ['inviter' => $this->inviter, 'site' => $this->site]))
            ->line(__(':inviter gave you view-only access to the analytics for :site.', ['inviter' => $this->inviter, 'site' => $this->site]))
            ->action(__('Open the report'), $this->url)
            ->line(__('This link is just for you. Keep it to yourself; it stops working if your access is removed.'));
    }
}
