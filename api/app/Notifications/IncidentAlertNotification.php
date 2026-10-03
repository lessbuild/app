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
    /**
     * Create a new IncidentAlertNotification instance.
     *
     * The email for a monitoring incident alert (or a test alert) sent to an email destination. Delivery and retries
     * are handled by the alert delivery runner, so this isn't queued.
     *
     * @param  string  $deliveryId  The delivery's ID, printed in the email so a recipient can quote it.
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public readonly string $deliveryId, public readonly array $payload) {}

    /**
     * Get the notification's delivery channels: email only; other destination types are delivered by their own
     * transports.
     *
     * @param  object  $notifiable
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Build the email: the event and incident title, where it happened, the monitor's latest observation with its
     * details, and a link to the incident. Sent through the monitoring mailer when one is configured.
     *
     * @param  object  $notifiable
     * @return MailMessage
     */
    public function toMail(object $notifiable): MailMessage
    {
        $event = $this->text('event');
        $message = new MailMessage;
        $mailer = config('monitoring.alerts.mailer');
        if (is_string($mailer) && $mailer !== '') {
            $message->mailer($mailer);
        }
        $label = $this->text('event_label', ucfirst($event));
        $subject = $this->text('kind') === 'deploy'
            ? __(':app: :event — :environment', ['app' => config('app.name'), 'event' => $label, 'environment' => $this->text('environment')])
            : __(':app incident: :event', ['app' => config('app.name'), 'event' => $label]);
        $message->subject($subject)
            ->line($label.': '.$this->text('title'))
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
            $message->action($this->text('url_label', __('View incident')), $url);
        }

        return $message
            ->line(__('Delivery ID: :id', ['id' => $this->deliveryId]))
            ->line(__('This is an automated monitoring notification. A test notification does not represent a real incident.'));
    }

    /**
     * Read a payload value as text, or return the default when it's missing or not scalar.
     *
     * @param  string  $key
     * @param  string  $default
     * @return string
     */
    private function text(string $key, string $default = ''): string
    {
        $value = $this->payload[$key] ?? null;

        return is_scalar($value) ? (string) $value : $default;
    }
}
