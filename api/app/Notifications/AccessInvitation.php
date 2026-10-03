<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Invites someone who asked for access to sign up, with a one-time link. */
final class AccessInvitation extends Notification
{
    use Queueable;

    /**
     * Create a new AccessInvitation instance.
     *
     * An invitation to sign up.
     *
     * @param  string  $url  The sign-up link carrying the one-time token.
     */
    public function __construct(private readonly string $url) {}

    /**
     * Get the delivery channels: email only, since they have no account yet.
     *
     * @param  object  $notifiable
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Build the invitation email.
     *
     * @param  object  $notifiable
     * @return MailMessage
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject(__('Your invitation to :app', ['app' => config('app.name')]))
            ->line(__('You asked for access, and it’s ready. The link works once and expires in :days days.', ['days' => config('platform.registration.invitation_days')]))
            ->action(__('Create your account'), $this->url);
    }
}
