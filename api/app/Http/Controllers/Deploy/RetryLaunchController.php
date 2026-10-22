<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Jobs\Deploy\RunLaunch;
use App\Models\Build;
use App\Models\Launch;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

final class RetryLaunchController
{
    /**
     * Carry on a launch that stopped, from where it stopped; what it made is kept. A failed deploy is deployed again.
     *
     * @param  Project  $project
     * @param  Launch  $launch
     * @return JsonResponse
     */
    public function __invoke(Project $project, Launch $launch): JsonResponse
    {
        if ($launch->status !== 'failed') {
            throw ValidationException::withMessages(['launch' => __('It’s still going.')]);
        }
        $result = $launch->result ?? [];
        // A deploy that failed is started again; anything else carries on as it was.
        foreach ($result['apps'] ?? [] as $index => $app) {
            if (isset($app['build_id']) && ! in_array(Build::query()->whereKey($app['build_id'])->value('status'), [Build::STATUS_SUCCEEDED, ...Build::ACTIVE], true)) {
                unset($result['apps'][$index]['build_id']);
            }
        }
        $launch->forceFill(['status' => 'queued', 'error' => null, 'result' => $result, 'created_at' => now()])->save();
        RunLaunch::dispatch($launch->id);

        return response()->json(['redirect' => route('deploy.launches.show', [$project, $launch], false), 'message' => __('Trying again from where it stopped.')]);
    }
}
