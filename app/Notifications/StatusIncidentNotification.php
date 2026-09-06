<?php

namespace App\Notifications;

use App\Models\StatusIncident;
use App\Models\StatusSubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

class StatusIncidentNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly StatusIncident $incident,
        private readonly StatusSubscription $subscription,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("[{$this->incident->status}] {$this->incident->title}")
            ->line($this->incident->message)
            ->line(__('Status: :status', ['status' => str($this->incident->status)->headline()]))
            ->action(__('View status page'), route('status.show', $this->incident->statusPage->slug))
            ->line(new HtmlString('<a href="'.e(route('status.subscriptions.unsubscribe', [$this->subscription, $this->subscription->unsubscribe_token])).'">'.e(__('Unsubscribe')).'</a>'));
    }
}
