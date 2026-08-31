<?php

namespace App\Observers;

use App\Actions\Server\DeleteCloudServerAction;
use App\Jobs\Web\CleanupWebsitePlacementJob;
use App\Models\Build;
use App\Models\Repository;
use App\Models\Server;
use App\Models\Website;
use App\Notifications\FailureNotification;
use App\Services\ActivityRecorder;

class ServerObserver
{
    public function __construct(
        private readonly DeleteCloudServerAction $deleteCloudServer,
        private readonly ActivityRecorder $activity,
    ) {}

    public function created(Server $server): void
    {
        $this->record($server, "Server \"{$server->label}\" was created.");
    }

    public function updated(Server $server): void
    {
        if ($server->wasChanged('provisioning_status')) {
            $this->record($server, "Server \"{$server->label}\" is {$server->provisioning_status}.");

            if ($server->provisioning_status === Server::STATUS_FAILED) {
                $server->user?->notify(new FailureNotification(
                    'server',
                    $server->id,
                    "Server \"{$server->label}\" failed",
                    $server->provisioning_error ?: 'Server provisioning failed before it completed.',
                ));
            }
        }
    }

    /**
     * Server Deleting
     *
     * @return void
     *
     * @throws \Exception
     */
    public function deleting(Server $server)
    {
        $this->deleteCloudServer->handle($server);

        $this->record($server, "Server \"{$server->label}\" was deleted.");

        Website::withTrashed()
            ->where('previous_server_id', $server->id)
            ->update([
                'previous_server_id' => null,
                'placement_cleanup_error' => null,
            ]);

        Website::withTrashed()->where('server_id', $server->id)->each(function (Website $website) use ($server): void {
            if ($website->previous_server_id && $website->previous_server_id !== $server->id) {
                CleanupWebsitePlacementJob::dispatch(
                    $website->id,
                    $website->previous_server_id,
                    $website->deployment_slug,
                );
            }
            Repository::withTrashed()
                ->where('website_id', $website->id)
                ->each(function (Repository $repository): void {
                    $repository->builds()->each(function (Build $build): void {
                        $build->logs()->delete();
                        $build->delete();
                    });
                    $repository->forceDelete();
                });

            // The whole droplet is gone, so no remote Caddy cleanup job is
            // necessary for each child website.
            $website->forceDeleteQuietly();
        });
    }

    private function record(Server $server, string $message): void
    {
        if ($server->user_id) {
            $this->activity->record($server, $server->user_id, 'server', $message);
        }
    }
}
