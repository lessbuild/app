<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class AccountSecurityNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $message) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array{category: string, resource_id: int, title: string, message: string, status: string} */
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
