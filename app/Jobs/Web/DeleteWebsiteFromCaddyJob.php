<?php

namespace App\Jobs\Web;

use App\Actions\Web\DeleteWebsitePlacementAction;
use App\Models\Build;
use App\Models\Repository;
use App\Models\Server;
use App\Models\Website;
use App\Services\Runner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class DeleteWebsiteFromCaddyJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $websiteId;

    public int $tries = 3;

    public int $backoff = 10;

    /**
     * Create a new job instance.
     */
    public function __construct(int $websiteId)
    {
        $this->websiteId = $websiteId;
    }

    /**
     * Execute the job.
     *
     *
     * @throws \Exception
     */
    public function handle(Runner $runner): void
    {
        $website = Website::withTrashed()->find($this->websiteId);
        if (! $website) {
            return;
        }

        // Remove the stale source first so a failure never takes the active
        // placement offline before the deletion can be finalized.
        collect([$website->previous_server_id, $website->server_id])
            ->filter()
            ->unique()
            ->each(function (int $serverId) use ($runner, $website): void {
                $server = Server::find($serverId);
                if ($server) {
                    (new DeleteWebsitePlacementAction($server, $website->deployment_slug, $runner))->handle();
                }
            });

        DB::transaction(function () use ($website): void {
            Repository::withTrashed()
                ->where('website_id', $website->id)
                ->each(function (Repository $repository): void {
                    $repository->builds()->each(function (Build $build): void {
                        $build->logs()->delete();
                        $build->delete();
                    });
                    $repository->forceDelete();
                });

            $website->logs()->delete();

            Website::withoutEvents(fn () => $website->forceDelete());
        });
    }

    public function failed(\Throwable $exception): void
    {
        $website = Website::withTrashed()->find($this->websiteId);
        if (! $website) {
            return;
        }

        $website->restore();
        $website->update([
            'provisioning_status' => Website::STATUS_FAILED,
            'provisioning_error' => str($exception->getMessage())->limit(2000),
        ]);
    }
}
