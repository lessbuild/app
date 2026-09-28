<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telemetry;

use App\Actions\Telemetry\RecordDeployment;
use App\Http\Requests\Telemetry\StoreDeploymentRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/** Record a deployment by hand (pipelines use POST /api/v1/deployments). */
final class StoreDeploymentController
{
    /**
     * Records a deployment by hand for one of the project's environments.
     *
     * @param  StoreDeploymentRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  RecordDeployment  $record
     * @return RedirectResponse
     */
    public function __invoke(StoreDeploymentRequest $request, #[CurrentUser] User $user, Project $project, RecordDeployment $record): RedirectResponse
    {
        $environment = $project->environments()->find($request->string('environment_id')->toString());
        if ($environment === null) {
            throw ValidationException::withMessages(['environment_id' => __('Choose one of this project’s environments.')]);
        }
        $deployment = $record->handle($environment, $request->deployment(), actor: $user);

        return to_route('monitoring.deployments.show', [$project, $deployment->id])->with('status', $deployment->wasRecentlyCreated
            ? __('Deployment recorded. Nothing was deployed; this only marks the release.')
            : __('This deployment was already recorded, so nothing changed.'));
    }
}
