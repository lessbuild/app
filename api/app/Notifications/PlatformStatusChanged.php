<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\PlatformStatusIncident;
use App\Models\PlatformStatusSubscriber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Symfony\Component\Mime\Email;

/** Tells a status subscriber that part of BuildPusher stopped working, or is working again. */
final class PlatformStatusChanged extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new PlatformStatusChanged instance.
     *
     * @param  PlatformStatusIncident  $incident  The incident, open or just resolved.
     * @param  PlatformStatusSubscriber  $subscriber  Who it's for, for the unsubscribe links.
     */
    public function __construct(
        public readonly PlatformStatusIncident $incident,
        public readonly PlatformStatusSubscriber $subscriber,
    ) {
        $this->afterCommit();
    }

    /**
     * Email only: subscribers are addresses, not users.
     *
     * @param  object  $notifiable
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Say what changed and when, link to the status page, and offer one-click unsubscribe.
     *
     * @param  object  $notifiable
     * @return MailMessage
     */
    public function toMail(object $notifiable): MailMessage
    {
        $resolved = $this->incident->resolved_at !== null;
        $page = route('unsubscribe.platform-status', [$this->subscriber->id, $this->subscriber->unsubscribe_token]);
        $oneClick = route('app.platform.status.unsubscribe', [$this->subscriber->id, $this->subscriber->unsubscribe_token]);

        return (new MailMessage)
            ->subject($resolved ? __('Resolved: :name is working again', ['name' => $this->incident->name]) : __(':name isn’t working as it should', ['name' => $this->incident->name]))
            ->greeting(__(':app status', ['app' => config('app.name')]))
            ->line($resolved
                ? __(':name is working normally again. It was affected from :start to :end (UTC).', ['name' => $this->incident->name, 'start' => $this->incident->started_at->utc()->format('M j, H:i'), 'end' => $this->incident->resolved_at->utc()->format('M j, H:i')])
                : __('Our checks found :name not working as it should from :start (UTC). We’re looking into it, and we’ll email again when it’s resolved.', ['name' => $this->incident->name, 'start' => $this->incident->started_at->utc()->format('M j, H:i')]))
            ->action(__('View status'), route('platform.status'))
            ->line(__('To stop these emails, unsubscribe: :url', ['url' => $page]))
            ->withSymfonyMessage(function (Email $email) use ($oneClick): void {
                $email->getHeaders()->addTextHeader('List-Unsubscribe', '<'.$oneClick.'>');
                $email->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            });
    }
}
