<?php

namespace App\Modules\Monitor\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class IncidentAlertNotification extends Notification
{
    /** @param array<string, mixed> $payload */
    public function __construct(public readonly string $deliveryId, public readonly array $payload) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->mailer(config('monitor.beacon.alerts.mailer'))
            ->subject(config('app.name').' incident: '.ucfirst($this->payload['event']))
            ->view(['html' => 'monitor::alerts.mail', 'text' => 'alerts.mail-text'], ['payload' => $this->payload, 'deliveryId' => $this->deliveryId]);
    }
}
