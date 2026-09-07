<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AlertEmailNotification extends Notification
{
    use Queueable;

    /**
     * Capture the incident event payload used to compose an email alert.
     *
     * @param  array<string, mixed>  $payload  Alert event attributes such as title, message, event, and category.
     */
    public function __construct(private readonly array $payload) {}

    /**
     * Deliver this notification through Laravel's mail channel.
     *
     * @param  object  $notifiable  Notification recipient whose delivery routing is resolved by Laravel.
     * @return list<string> The single mail channel used for this notification.
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Compose the event title and message, falling back to generic alert text when details are absent.
     *
     * @param  object  $notifiable  Notification recipient whose delivery routing is resolved by Laravel.
     * @return MailMessage The composed email message for Laravel to deliver to the recipient.
     */
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
