<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Number;

/** A client's monthly report, sent in the agency's name. */
final class ClientReportNotification extends Notification
{
    /**
     * Create a new ClientReportNotification instance.
     *
     * @param  string  $sender  The agency's name (its brand, or the account's name).
     * @param  string  $clientName  Who it's for.
     * @param  array{month: string, label: string, currency: string, projects: list<array{name: string, uptime: float|null, incidents: int, deploys: int, visitors: int|null, cost: array<string, float>}>, total: array<string, float>}  $report  The month.
     */
    public function __construct(public readonly string $sender, public readonly string $clientName, public readonly array $report) {}

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
     * Build the email: a line per project and the month's total.
     *
     * @param  object  $notifiable
     * @return MailMessage
     */
    public function toMail(object $notifiable): MailMessage
    {
        $money = fn (array $amounts): string => $amounts === [] ? '—' : collect($amounts)->map(fn (float $amount, string $currency): string => (string) Number::currency($amount, $currency))->implode(' + ');
        $message = (new MailMessage)->from((string) config('mail.from.address'), $this->sender)
            ->subject(__(':client: your report for :month', ['client' => $this->clientName, 'month' => $this->report['label']]))
            ->greeting(__('Your :month report', ['month' => $this->report['label']]));
        foreach ($this->report['projects'] as $project) {
            $message->line('**'.$project['name'].'**: '.collect([
                $project['uptime'] === null ? null : __(':uptime% uptime', ['uptime' => number_format($project['uptime'], 2)]),
                trans_choice(':count incident|:count incidents', $project['incidents']),
                trans_choice(':count release|:count releases', $project['deploys']),
                $project['visitors'] === null ? null : trans_choice(':count visitor|:count visitors', $project['visitors'], ['count' => number_format($project['visitors'])]),
                $project['cost'] === [] ? null : __('costs :amount a month', ['amount' => $money($project['cost'])]),
            ])->filter()->implode(' · '));
        }
        if ($this->report['total'] !== []) {
            $message->line(__('Total: :amount a month.', ['amount' => $money($this->report['total'])]));
        }

        return $message->salutation(__('— :sender', ['sender' => $this->sender]));
    }
}
