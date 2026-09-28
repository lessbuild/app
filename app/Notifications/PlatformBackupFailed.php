<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\PlatformBackup;
use Illuminate\Notifications\Messages\MailMessage;

/** A backup of the platform's database failed. Sent to platform admins. */
final class PlatformBackupFailed extends InboxNotification
{
    /**
     * Create a new PlatformBackupFailed instance.
     *
     * @param  PlatformBackup  $backup  The backup that failed.
     */
    public function __construct(private readonly PlatformBackup $backup) {}

    /**
     * Get the delivery channels: email and the inbox.
     *
     * @param  object  $notifiable
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Build the email with a link to the backups.
     *
     * @param  object  $notifiable
     * @return MailMessage
     */
    public function toMail(object $notifiable): MailMessage
    {
        return $this->mailWithAction(__('See backups'));
    }

    /**
     * Get the headline.
     *
     * @return string
     */
    protected function title(): string
    {
        return __('A platform database backup failed');
    }

    /**
     * Get why it failed.
     *
     * @return string
     */
    protected function body(): string
    {
        return $this->backup->error ?? __('No reason was recorded.');
    }

    /**
     * Get the admin panel's backups page.
     *
     * @return string
     */
    protected function url(): string
    {
        return route('admin.backups');
    }
}
