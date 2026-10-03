<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Jobs\Deploy\PublishBuildArtifact;
use App\Models\Build;

final class ReleaseBuildArtifact
{
    /**
     * Hand a build from its build server to its website's server once the built release is uploaded. Only a running
     * build still in its build part moves on, so a repeated report does nothing.
     *
     * @param  Build  $build
     * @return void
     */
    public function handle(Build $build): void
    {
        $moved = Build::query()->whereKey($build->id)->where('status', Build::STATUS_RUNNING)->where('build_phase', 'build')
            ->update(['build_phase' => 'release', 'last_heartbeat_at' => now(), 'remote_process_id' => null, 'remote_process_path' => null]);
        if ($moved === 1) {
            PublishBuildArtifact::dispatch($build->id);
        }
    }
}
