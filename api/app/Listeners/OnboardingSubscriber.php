<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use App\Notifications\Onboarding\Welcome;
use Illuminate\Auth\Events\Verified;
use Illuminate\Events\Dispatcher;

/** Sends the welcome email once someone confirms their address. */
final class OnboardingSubscriber
{
    /**
     * Register the events this subscriber handles.
     *
     * @param  Dispatcher  $events
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [Verified::class => 'welcome'];
    }

    /**
     * Welcome someone who just confirmed their address, unless they've turned getting-started emails off.
     *
     * @param  Verified  $event
     * @return void
     */
    public function welcome(Verified $event): void
    {
        // A just-created model may not have loaded the column yet; it defaults to on.
        if ($event->user instanceof User && $event->user->getting_started_emails !== false) {
            $event->user->notify(new Welcome);
        }
    }
}
