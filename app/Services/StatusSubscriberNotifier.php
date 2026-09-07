<?php

namespace App\Services;

use App\Models\StatusIncident;
use App\Notifications\StatusIncidentNotification;
use Illuminate\Support\Facades\Notification;

class StatusSubscriberNotifier
{
    /**
     * Notify verified email subscribers of the incident's current status.
     *
     * @param  StatusIncident  $incident  The incident whose status page supplies verified subscriptions.
     * @return void No value; routes one incident notification to each verified subscriber.
     */
    public function send(StatusIncident $incident): void
    {
        $incident->statusPage->subscriptions()->whereNotNull('verified_at')->each(function ($subscription) use ($incident): void {
            Notification::route('mail', $subscription->email)
                ->notify(new StatusIncidentNotification($incident, $subscription));
        });
    }
}
