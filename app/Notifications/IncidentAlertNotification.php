<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Data\Monitoring\MonitorObservation;
use App\Support\Monitoring\ObservationText;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** An alert email. Sent synchronously by the alert delivery job, which records the outcome. */
final class IncidentAlertNotification extends Notification
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
        $event = $this->text('event');
        $message = new MailMessage;
        $mailer = config('monitoring.alerts.mailer');
        if (is_string($mailer) && $mailer !== '') {
            $message->mailer($mailer);
        }
        $message->subject(__(':app incident: :event', ['app' => config('app.name'), 'event' => ucfirst($event)]))
            ->line(ucfirst($event).': '.$this->text('title'))
            ->line($this->text('project', $this->text('application')).' / '.$this->text('environment'));

        $monitor = $this->payload['monitor'] ?? null;
        $observation = is_array($this->payload['observation'] ?? null) ? $this->payload['observation'] : [];
        if (is_array($monitor)) {
            $type = is_string($monitor['type'] ?? null) ? $monitor['type'] : '';
            $reason = is_string($observation['reason'] ?? null) ? $observation['reason'] : null;
            $status = is_scalar($observation['http_status'] ?? null) ? (string) $observation['http_status'] : '—';
            $message->line(strtoupper($type).' monitor: '.(is_string($monitor['name'] ?? null) ? $monitor['name'] : '')
                .' · '.MonitorObservation::label($reason).($type === 'http' ? ' · HTTP '.$status : ''));
            foreach (ObservationText::details(is_array($observation['details'] ?? null) ? $observation['details'] : []) as $line) {
                $message->line($line);
            }
        }

        $url = $this->payload['url'] ?? null;
        if (is_string($url)) {
            $message->action(__('View incident'), $url);
        }

        return $message
            ->line(__('Delivery ID: :id', ['id' => $this->deliveryId]))
            ->line(__('This is an automated monitoring notification. A test notification does not represent a real incident.'));
    }

    private function text(string $key, string $default = ''): string
    {
        $value = $this->payload[$key] ?? null;

        return is_scalar($value) ? (string) $value : $default;
    }
}
