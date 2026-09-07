<?php

namespace App\Jobs\Web;

use App\Actions\Web\DeleteWebsitePlacementAction;
use App\Models\Server;
use App\Models\Website;
use App\Services\Runner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CleanupWebsitePlacementJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    /**
     * Capture a former website placement so cleanup does not depend on its current server.
     *
     * @param  int  $websiteId  Website identifier retained for lookup when the job runs.
     * @param  int  $serverId  Managed server identifier retained for remote work when the job runs.
     * @param  string  $deploymentSlug  Stable website identifier used in application paths and Caddy configuration names.
     */
    public function __construct(
        public readonly int $websiteId,
        public readonly int $serverId,
        public readonly string $deploymentSlug,
    ) {}

    /**
     * Remove the former placement when its server exists, then clear previous-placement metadata only if it still references that server.
     *
     * @param  Runner  $runner  SSH runner used to execute commands on the selected managed server.
     */
    public function handle(Runner $runner): void
    {
        $server = Server::find($this->serverId);
        if ($server) {
            (new DeleteWebsitePlacementAction($server, $this->deploymentSlug, $runner))->handle();
        }

        Website::withTrashed()
            ->whereKey($this->websiteId)
            ->where('previous_server_id', $this->serverId)
            ->update([
                'previous_server_id' => null,
                'placement_cleanup_error' => null,
            ]);
    }

    /**
     * Store a bounded cleanup error only while the website still references the captured previous server.
     *
     * @param  \Throwable  $exception  Failure delivered by the queue after this job cannot complete successfully.
     */
    public function failed(\Throwable $exception): void
    {
        Website::withTrashed()
            ->whereKey($this->websiteId)
            ->where('previous_server_id', $this->serverId)
            ->update([
                'placement_cleanup_error' => str($exception->getMessage())->limit(2000),
            ]);
    }
}
