<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class AccountSecurityNotification extends Notification
{
    use Queueable;

    /**
     * Capture the account security event text for the recipient's private inbox.
     *
     * @param  string  $message  Security event description, bounded when the database payload is built.
     */
    public function __construct(private readonly string $message) {}

    /**
     * Deliver this notification through Laravel's database channel.
     *
     * @param  object  $notifiable  Notification recipient whose delivery routing is resolved by Laravel.
     * @return list<string> The single database channel used for this notification.
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Build the private account inbox payload with the recipient's resource ID and a bounded security message.
     *
     * @param  object  $notifiable  Account recipient exposing getKey() for the inbox resource identifier.
     * @return array{category: string, resource_id: int, title: string, message: string, status: string} Account-category inbox data with informational status and at most 500 message characters.
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'category' => 'account',
            'resource_id' => (int) $notifiable->getKey(),
            'title' => 'Account security changed',
            'message' => str($this->message)->limit(500, '')->toString(),
            'status' => NotificationInbox::STATUS_INFO,
        ];
    }
}
