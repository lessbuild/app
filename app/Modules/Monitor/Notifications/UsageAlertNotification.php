<?php

namespace App\Modules\Monitor\Notifications;

use App\Modules\Monitor\Models\Workspace;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class UsageAlertNotification extends Notification
{
    /**
     * @param  array<string, mixed>  $summary
     */
    public function __construct(
        public readonly Workspace $workspace,
        public readonly array $summary,
        public readonly int $threshold,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(config('app.name').' usage alert · '.$this->workspace->name)
            ->markdown('mail.usage-alert', [
                'workspace' => $this->workspace,
                'summary' => $this->summary,
                'threshold' => $this->threshold,
                'billingUrl' => route('monitor.settings.billing'),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'workspace_id' => $this->workspace->id,
            'threshold' => $this->threshold,
            'event_count' => $this->summary['event_count'],
            'event_limit' => $this->summary['event_limit'],
        ];
    }
}
