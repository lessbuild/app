<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\BackupVerification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A monthly restore drill found a backup that doesn't restore cleanly. */
final class RestoreDrillFailedNotification extends Notification
{
    /**
     * Create a new RestoreDrillFailedNotification instance.
     *
     * @param  BackupVerification  $verification  The drill that failed.
     * @param  string  $url  The website's backups.
     */
    public function __construct(public readonly BackupVerification $verification, public readonly string $url) {}

    /**
     * Get the notification's delivery channels: email.
     *
     * @param  object  $notifiable
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Build the email: which website's backup failed its drill, at what stage, and where to look.
     *
     * @param  object  $notifiable
     * @return MailMessage
     */
    public function toMail(object $notifiable): MailMessage
    {
        $website = $this->verification->backup->website->name;

        return (new MailMessage)
            ->subject(__('Restore drill failed for :website', ['website' => $website]))
            ->line(__('This month’s restore drill couldn’t restore the latest backup of :website cleanly.', ['website' => $website]))
            ->line((string) $this->verification->error)
            ->action(__('See the backups'), $this->url)
            ->line(__('Fix it now, while nothing is broken: a backup that doesn’t restore isn’t a backup.'));
    }
}
