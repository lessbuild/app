<?php

declare(strict_types=1);

namespace App\Notifications\Onboarding;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** One reminder a few days after signing up: the next setup step, or making a first project. Sent once. */
final class NextStep extends Notification
{
    /**
     * Create a new NextStep instance.
     *
     * @param  string|null  $project  The project being set up, or null when there isn't one yet.
     * @param  string  $step  The next step's title.
     * @param  string  $url  Where to do it.
     * @param  int  $done  How many steps are done.
     * @param  int  $total  How many there are.
     */
    public function __construct(private readonly ?string $project, private readonly string $step, private readonly string $url, private readonly int $done, private readonly int $total) {}

    /**
     * Get the delivery channels: email only.
     *
     * @param  object  $notifiable
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Build the email.
     *
     * @param  object  $notifiable
     * @return MailMessage
     */
    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)->subject($this->project === null
            ? __('Your first project is a minute away')
            : __(':project: next, :step', ['project' => $this->project, 'step' => mb_strtolower($this->step)]));

        $message = $this->project === null
            ? $message->line(__('You signed up a few days ago but haven’t made a project yet. A project is one app or site; its setup guide walks you through the rest.'))
            : $message->line(__(':project is :done of :total steps set up. Next: :step.', ['project' => $this->project, 'done' => $this->done, 'total' => $this->total, 'step' => $this->step]));

        return $message
            ->action($this->project === null ? __('Create a project') : __('Carry on setting up'), $this->url)
            ->line(__('Stuck? Reply to this email and a person will help.'))
            ->line(__('This is the only reminder we’ll send. [Stop getting-started emails](:url).', ['url' => Welcome::unsubscribeUrl($notifiable)]));
    }
}
