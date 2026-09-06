<?php

namespace App\Services;

use App\Models\StatusIncident;
use App\Notifications\StatusIncidentNotification;
use Illuminate\Support\Facades\Notification;

class StatusSubscriberNotifier
{
    public function send(StatusIncident $incident): void
    {
        $incident->statusPage->subscriptions()->whereNotNull('verified_at')->each(function ($subscription) use ($incident): void {
            Notification::route('mail', $subscription->email)
                ->notify(new StatusIncidentNotification($incident, $subscription));
        });
    }
}
