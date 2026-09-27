<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\StatusSubscription;
use App\Models\StatusUpdate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Symfony\Component\Mime\Email;

/** Tells a confirmed subscriber that a status page posted or changed an incident or maintenance notice. */
final class StatusUpdateNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Emails a confirmed subscriber about an incident or maintenance update on a status page. Sent after the transaction
     * commits.
     *
     * @param  StatusUpdate  $update  The update being published.
     * @param  StatusSubscription  $subscription  The subscriber, for the personal unsubscribe link.
     */
    public function __construct(public readonly StatusUpdate $update, public readonly StatusSubscription $subscription)
    {
        $this->afterCommit();
    }

    /**
     * Email only: subscribers are addresses, not users.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * The update with its status and any root cause, remediation and follow-up, a link to the page, and one-click
     * unsubscribe headers so mail clients can offer it.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $page = $this->update->statusPage;
        $unsubscribe = route('status.subscriptions.unsubscribe', [$this->subscription->id, $this->subscription->unsubscribe_token]);
        $message = (new MailMessage)
            ->subject(__('[:status] :title', ['status' => $this->update->statusLabel(), 'title' => $this->update->title]))
            ->greeting($page->name)
            ->line($this->update->message)
            ->line(__('Status: :status', ['status' => $this->update->statusLabel()]));
        foreach (['root_cause' => __('What happened'), 'remediation' => __('What we did'), 'follow_up' => __('What’s next')] as $field => $label) {
            if (filled($this->update->{$field})) {
                $message->line($label.': '.$this->update->{$field});
            }
        }

        return $message
            ->action(__('View status page'), route('status.show', $page->slug))
            ->line(__('To stop these emails, unsubscribe: :url', ['url' => $unsubscribe]))
            ->withSymfonyMessage(function (Email $email) use ($unsubscribe): void {
                $email->getHeaders()->addTextHeader('List-Unsubscribe', '<'.$unsubscribe.'>');
                $email->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            });
    }
}
