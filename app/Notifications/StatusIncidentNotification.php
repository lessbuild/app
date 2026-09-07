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

    /**
     * Capture the public incident update and the subscriber's unsubscribe identity.
     *
     * @param  StatusIncident  $incident  Public incident whose current state and message should be announced.
     * @param  StatusSubscription  $subscription  Subscriber whose status page and unsubscribe token supply the notification links.
     */
    public function __construct(
        private readonly StatusIncident $incident,
        private readonly StatusSubscription $subscription,
    ) {}

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
     * Compose the incident status update with public-page and subscription-cancellation links.
     *
     * @param  object  $notifiable  Notification recipient whose delivery routing is resolved by Laravel.
     * @return MailMessage The composed email message for Laravel to deliver to the recipient.
     */
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
