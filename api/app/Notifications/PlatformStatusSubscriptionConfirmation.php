<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\PlatformStatusSubscriber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

/** Asks someone who subscribed to BuildPusher's status to confirm their address. */
final class PlatformStatusSubscriptionConfirmation extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new PlatformStatusSubscriptionConfirmation instance.
     *
     * Confirming first means nobody can subscribe someone else. Sent after the transaction commits.
     *
     * @param  PlatformStatusSubscriber  $subscriber  The unconfirmed subscriber.
     * @param  string  $token  The plain confirmation token for the link; only its hash is stored.
     */
    public function __construct(
        public readonly PlatformStatusSubscriber $subscriber,
        #[SensitiveParameter] public readonly string $token,
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
     * Explain what they'll get, link to the confirmation, and say to ignore it if they didn't ask.
     *
     * @param  object  $notifiable
     * @return MailMessage
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Confirm :app status updates', ['app' => config('app.name')]))
            ->line(__('Confirm your address to get an email when part of :app stops working, and again when it’s fixed.', ['app' => config('app.name')]))
            ->action(__('Confirm status updates'), route('confirm.platform-status', [$this->subscriber->id, $this->token]))
            ->line(__('If you didn’t ask for this, ignore this email and you won’t hear from us.'));
    }
}
