<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\Build;

final class RecordBuildLog
{
    /**
     * The latest tail of the deployment log (signed callback, every few seconds while it runs).
     *
     * @param  Build  $build
     * @param  string  $log
     * @return void
     */
    public function handle(Build $build, string $log): void
    {
        $log = mb_substr(str_replace("\0", '', $log), -max(1, (int) config('deploy.deployment_log_max_characters')));
        Build::query()->whereKey($build->id)->whereIn('status', [Build::STATUS_DEPLOYING, Build::STATUS_RUNNING])->first()
            ?->forceFill(['log' => $log, 'last_heartbeat_at' => now()])->save();
    }
}
