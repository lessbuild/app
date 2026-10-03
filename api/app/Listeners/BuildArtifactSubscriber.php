<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\Deploy\DeployFinished;
use App\Services\Deploy\BuildServers;
use Illuminate\Events\Dispatcher;

final class BuildArtifactSubscriber
{
    /**
     * Create a new BuildArtifactSubscriber instance.
     *
     * @param  BuildServers  $buildServers  Deletes built releases from the bucket.
     */
    public function __construct(private readonly BuildServers $buildServers) {}

    /**
     * Register for finished deploys.
     *
     * @param  Dispatcher  $events
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [DeployFinished::class => 'finished'];
    }

    /**
     * Delete a finished deploy's built release from the storage bucket, whatever the outcome; the website's server
     * keeps the release itself.
     *
     * @param  DeployFinished  $event
     * @return void
     */
    public function finished(DeployFinished $event): void
    {
        $this->buildServers->forget($event->build);
    }
}
