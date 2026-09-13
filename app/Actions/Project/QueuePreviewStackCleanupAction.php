<?php

namespace App\Actions\Project;

use App\Jobs\Project\CleanupPreviewStackJob;
use App\Models\Environment;
use App\Models\PreviewDeployment;
use App\Models\PreviewStackCleanup;
use App\Models\Website;
use App\Services\PreviewStackCleanupManifest;
use Illuminate\Support\Facades\DB;

class QueuePreviewStackCleanupAction
{
    /**
     * Bind safe manifest capture before dispatching durable preview cleanup.
     *
     * @param  PreviewStackCleanupManifest  $manifest  Captures only generated, non-secret remote identifiers.
     */
    public function __construct(private readonly PreviewStackCleanupManifest $manifest) {}

    /**
     * Capture the closed preview environment and queue one idempotent cleanup attempt for it.
     *
     * The environment identity is part of the cleanup key. Reopening a pull request therefore
     * cannot make a cleanup job for an older environment operate on the new stack.
     *
     * @param  PreviewDeployment  $preview  Closed preview whose current environment is being released.
     * @return PreviewStackCleanup|null The captured cleanup, or null when the preview is not eligible.
     */
    public function handle(PreviewDeployment $preview): ?PreviewStackCleanup
    {
        $dispatch = false;
        $cleanup = DB::transaction(function () use ($preview, &$dispatch): ?PreviewStackCleanup {
            $locked = PreviewDeployment::query()->lockForUpdate()->find($preview->id);
            if (! $locked || $locked->status !== PreviewDeployment::STATUS_CLOSED || ! $locked->environment_id) {
                return null;
            }

            $environment = Environment::query()->find($locked->environment_id);
            if (! $environment || $environment->type !== 'preview') {
                return null;
            }

            $website = Website::withTrashed()->find($locked->website_id ?: $environment->website_id);
            $processes = $this->manifest->processes($environment);
            $resources = $this->manifest->for($environment);
            if ($processes === [] && $resources === []) {
                return null;
            }

            $cleanup = PreviewStackCleanup::query()
                ->where('preview_deployment_id', $locked->id)
                ->where('environment_id', $environment->id)
                ->lockForUpdate()
                ->first();

            if (! $cleanup) {
                $cleanup = PreviewStackCleanup::query()->create([
                    'preview_deployment_id' => $locked->id,
                    'environment_id' => $environment->id,
                    'website_id' => $website?->id,
                    'server_id' => $environment->server_id ?: $website?->server_id,
                    'deployment_slug' => (string) ($website?->deployment_slug ?? ''),
                    'process_manifest' => $processes,
                    'resource_manifest' => $resources,
                    'status' => PreviewStackCleanup::STATUS_QUEUED,
                ]);
                $dispatch = true;
            } elseif ($cleanup->status !== PreviewStackCleanup::STATUS_SUCCEEDED
                && ($cleanup->status !== PreviewStackCleanup::STATUS_RUNNING
                    || ! $cleanup->lease_expires_at?->isFuture())) {
                $cleanup->update([
                    'status' => PreviewStackCleanup::STATUS_QUEUED,
                    'error' => null,
                    'lease_expires_at' => null,
                    'completed_at' => null,
                ]);
                $dispatch = true;
            }

            return $cleanup->fresh();
        }, 5);

        if ($dispatch && $cleanup) {
            CleanupPreviewStackJob::dispatch($cleanup->id)->afterCommit();
        }

        return $cleanup;
    }
}
