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

    public function __construct(
        public readonly int $websiteId,
        public readonly int $serverId,
        public readonly string $deploymentSlug,
    ) {}

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
