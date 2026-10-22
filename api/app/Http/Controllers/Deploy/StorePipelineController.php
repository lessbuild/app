<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\SavePipeline;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StorePipelineController
{
    /**
     * Create a deploy pipeline from the chosen steps and return to the pipelines.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  SavePipeline  $save
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, SavePipeline $save): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'steps' => ['required', 'array', 'max:8'], 'steps.*' => ['nullable', 'integer'], 'together' => ['nullable', 'array'], 'together.*' => ['nullable', 'boolean']]);
        // Empty steps are dropped, so "start with the step before" is renumbered to the steps that are left.
        $steps = [];
        $together = [];
        foreach ($data['steps'] as $index => $step) {
            if ((int) $step > 0) {
                if (count($steps) > 0 && filter_var($data['together'][$index] ?? false, FILTER_VALIDATE_BOOL)) {
                    $together[] = count($steps);
                }
                $steps[] = (int) $step;
            }
        }
        $save->handle($user, $project, $data['name'], $steps, $together);

        return response()->json(['redirect' => route('deploy.pipelines', $project, false), 'message' => __('Pipeline saved.')]);
    }
}
