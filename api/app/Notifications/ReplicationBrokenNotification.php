<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Server;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A read replica stopped following its primary, so it serves increasingly old data. */
final class ReplicationBrokenNotification extends Notification
{
    /**
     * Create a new ReplicationBrokenNotification instance.
     *
     * @param  Server  $replica  The replica that stopped.
     * @param  string  $url  The replica's page.
     */
    public function __construct(public readonly Server $replica, public readonly string $url) {}

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
     * Build the email: which replica stopped, why, and where to look.
     *
     * @param  object  $notifiable
     * @return MailMessage
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Read replica :name stopped copying', ['name' => $this->replica->name]))
            ->line(__(':name stopped following :primary, so reads from it are getting out of date.', ['name' => $this->replica->name, 'primary' => $this->replica->replicaOf->name ?? __('its primary')]))
            ->line((string) $this->replica->replication_error)
            ->action(__('See the replica'), $this->url)
            ->line(__('Setting it up again takes a fresh copy of the primary.'));
    }
}
