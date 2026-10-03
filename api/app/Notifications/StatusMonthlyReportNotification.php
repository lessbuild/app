<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\StatusPage;
use App\Models\StatusSubscription;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Last month's uptime report for a status page's subscribers. */
final class StatusMonthlyReportNotification extends Notification
{
    /**
     * Create a new StatusMonthlyReportNotification instance.
     *
     * @param  StatusPage  $page  The status page.
     * @param  StatusSubscription  $subscription  The subscriber, for the unsubscribe link.
     * @param  array{month: string, label: string, uptime: float|null, downtime_minutes: int, components: list<array{name: string, group: string|null, uptime: float|null, checks: int}>, incidents: list<array<string, mixed>>}  $report  The month's report.
     */
    public function __construct(public readonly StatusPage $page, public readonly StatusSubscription $subscription, public readonly array $report) {}

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
     * Build the email: overall uptime, incidents and downtime, each component's uptime, and links.
     *
     * @param  object  $notifiable
     * @return MailMessage
     */
    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject(__(':page: uptime in :month', ['page' => $this->page->name, 'month' => $this->report['label']]))
            ->line($this->report['uptime'] === null ? __('Not enough data for an uptime figure.') : __(':uptime% overall uptime.', ['uptime' => number_format($this->report['uptime'], 3)]))
            ->line(trans_choice(':count incident, :minutes minutes of downtime.|:count incidents, :minutes minutes of downtime.', count($this->report['incidents']), ['count' => count($this->report['incidents']), 'minutes' => number_format($this->report['downtime_minutes'])]));
        foreach ($this->report['components'] as $row) {
            $message->line($row['name'].': '.($row['uptime'] === null ? '—' : number_format($row['uptime'], 3).'%'));
        }

        return $message->action(__('See the report'), route('status.month', [$this->page->slug, $this->report['month']]))
            ->line(__('Unsubscribe: :url', ['url' => route('status.subscriptions.unsubscribe', [$this->subscription->id, $this->subscription->unsubscribe_token])]));
    }
}
