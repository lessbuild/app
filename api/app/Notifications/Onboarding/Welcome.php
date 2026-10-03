<?php

declare(strict_types=1);

namespace App\Notifications\Onboarding;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/** The welcome email once someone has confirmed their address: where to start, and where to get help. */
final class Welcome extends Notification
{
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
        $name = $notifiable instanceof User ? $notifiable->name : null;

        return (new MailMessage)
            ->subject(__('Welcome to :app', ['app' => config('app.name')]))
            ->greeting($name ? __('Welcome, :name!', ['name' => $name]) : __('Welcome!'))
            ->line(__('You’re set up. The quickest way in is to create a project: its setup guide takes you from connecting a cloud provider to a deployed, monitored site, one step at a time.'))
            ->action(__('Create your first project'), route('dashboard', ['dialog' => 'new-project']))
            ->line(__('Rather look around first? Open a sample project full of made-up data from your dashboard.'))
            ->line(__('Short guides for every part of :app are in the help centre: :url', ['app' => config('app.name'), 'url' => route('help')]))
            ->salutation(__('Happy shipping'))
            ->line(__('Don’t want tips like this? [Stop getting-started emails](:url).', ['url' => self::unsubscribeUrl($notifiable)]));
    }

    /**
     * Build the one-click link that turns getting-started emails off, signed so it works without signing in.
     *
     * @param  object  $notifiable
     * @return string
     */
    public static function unsubscribeUrl(object $notifiable): string
    {
        return $notifiable instanceof User ? URL::signedRoute('getting-started-emails.stop', ['user' => $notifiable->id]) : route('settings.notifications');
    }
}
