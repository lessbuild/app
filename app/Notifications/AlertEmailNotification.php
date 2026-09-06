<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AlertEmailNotification extends Notification
{
    use Queueable;

    /** @param array<string, mixed> $payload */
    public function __construct(private readonly array $payload) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject((string) ($this->payload['title'] ?? 'BuildPusher alert'))
            ->line((string) ($this->payload['message'] ?? 'An event requires attention.'))
            ->line(__('Event: :event · Category: :category', [
                'event' => $this->payload['event'] ?? 'unknown',
                'category' => $this->payload['category'] ?? 'unknown',
            ]));
    }
}
