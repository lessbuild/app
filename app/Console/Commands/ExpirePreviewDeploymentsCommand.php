<?php

namespace App\Console\Commands;

use App\Models\PreviewDeployment;
use App\Services\PreviewDeploymentLifecycle;
use Illuminate\Console\Command;

class ExpirePreviewDeploymentsCommand extends Command
{
    protected $signature = 'buildpusher:previews:expire';

    protected $description = 'Remove preview environments that exceeded their configured lifetime';

    public function handle(PreviewDeploymentLifecycle $lifecycle): int
    {
        $expired = 0;
        PreviewDeployment::query()
            ->with('project')
            ->where('status', '!=', PreviewDeployment::STATUS_CLOSED)
            ->orderBy('id')
            ->chunkById(100, function ($previews) use ($lifecycle, &$expired): void {
                foreach ($previews as $preview) {
                    if ($preview->last_activity_at->lte(now()->subHours($preview->project->preview_ttl_hours))) {
                        $lifecycle->expire($preview);
                        $expired++;
                    }
                }
            });

        $this->info("Expired {$expired} preview environment(s).");

        return self::SUCCESS;
    }
}
