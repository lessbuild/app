<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\Deploy\DeployAwaitingApproval;
use App\Events\Deploy\DeployFinished;
use App\Models\Build;
use App\Services\Deploy\DeployNotifications;
use Illuminate\Events\Dispatcher;

/** Sends deploy outcomes to the destinations environments chose. */
final class DeployNotificationSubscriber
{
    /**
     * Create a new DeployNotificationSubscriber instance.
     *
     * @param  DeployNotifications  $notifications  Queues the deliveries.
     */
    public function __construct(private readonly DeployNotifications $notifications) {}

    /**
     * Register the events this subscriber handles.
     *
     * @param  Dispatcher  $events
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [DeployFinished::class => 'finished', DeployAwaitingApproval::class => 'awaitingApproval'];
    }

    /**
     * Notify about a deploy that went live or failed (cancelled ones are left quiet).
     *
     * @param  DeployFinished  $event
     * @return void
     */
    public function finished(DeployFinished $event): void
    {
        match ($event->build->status) {
            Build::STATUS_SUCCEEDED => $this->notifications->send($event->build, 'deploy_succeeded'),
            Build::STATUS_FAILED => $this->notifications->send($event->build, 'deploy_failed'),
            default => null,
        };
    }

    /**
     * Notify about a deploy waiting for approval.
     *
     * @param  DeployAwaitingApproval  $event
     * @return void
     */
    public function awaitingApproval(DeployAwaitingApproval $event): void
    {
        $this->notifications->send($event->build, 'deploy_approval');
    }
}
