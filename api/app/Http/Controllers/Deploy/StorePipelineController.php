<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\SavePipeline;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
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
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, SavePipeline $save): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'steps' => ['required', 'array', 'max:8'], 'steps.*' => ['nullable', 'integer']]);
        $steps = array_values(array_filter(array_map('intval', $data['steps'])));
        $save->handle($user, $project, $data['name'], $steps);

        return to_route('deploy.pipelines', $project)->with('status', __('Pipeline saved.'));
    }
}
