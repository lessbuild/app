<?php

namespace App\Http\Controllers;

use App\Actions\Environment\CreateEnvironmentAction;
use App\Actions\Environment\DeleteEnvironmentAction;
use App\Actions\Environment\DeleteEnvironmentChildAction;
use App\Actions\Environment\SaveEnvironmentProcessAction;
use App\Actions\Environment\SaveEnvironmentResourceAction;
use App\Actions\Environment\SaveEnvironmentVariableAction;
use App\Actions\Environment\UpdateDeploymentControlsAction;
use App\Actions\Environment\UpdateEnvironmentAction;
use App\Exceptions\EnvironmentDeletionException;
use App\Http\Requests\DeploymentControlsRequest;
use App\Http\Requests\EnvironmentRequest;
use App\Http\Requests\StoreEnvironmentProcessRequest;
use App\Http\Requests\StoreEnvironmentResourceRequest;
use App\Http\Requests\StoreEnvironmentVariableRequest;
use App\Models\Environment;
use App\Models\EnvironmentProcess;
use App\Models\EnvironmentResource;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class EnvironmentController extends Controller
{
    /**
     * Validate environment placement and runtime settings for an editable application and assign an available slug.
     *
     * @return RedirectResponse The creation result, or a validation error for a second production environment.
     */
    public function store(EnvironmentRequest $request, Project $project, CreateEnvironmentAction $createEnvironment): RedirectResponse
    {
        $this->authorize('update', $project);
        $data = $request->validated();
        try {
            $createEnvironment->handle($project, $request->user(), $data);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        }

        return back()->with('success', __('Environment created.'));
    }

    /**
     * Validate environment settings while retaining omitted runtime values and enforcing changed-feature entitlements.
     *
     * @return RedirectResponse The saved result, or a validation error for a second production environment.
     */
    public function update(EnvironmentRequest $request, Environment $environment, UpdateEnvironmentAction $updateEnvironment): RedirectResponse
    {
        $this->authorize('update', $environment);
        $data = $request->validated();
        try {
            $updateEnvironment->handle($environment, $data);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        }

        return back()->with('success', __('Environment updated.'));
    }

    /**
     * Validate a key, value, scope, and optional rotation date for an editable environment and append an encrypted version.
     */
    public function variables(StoreEnvironmentVariableRequest $request, Environment $environment, SaveEnvironmentVariableAction $saveVariable): RedirectResponse
    {
        $this->authorize('update', $environment);
        $saveVariable->handle($environment, $request->user(), $request->validated(), $request->isSecret());

        return back()->with('success', __('Environment variable saved securely.'));
    }

    /** Delete the route-bound variable only from its authorized parent environment. */
    public function destroyVariable(Environment $environment, EnvironmentVariable $variable, DeleteEnvironmentChildAction $deleteChild): RedirectResponse
    {
        $this->authorize('update', $environment);
        $deleteChild->handle($variable);

        return back()->with('success', __('Environment variable deleted.'));
    }

    /**
     * Validate an entitled environment process definition and restart policy, limiting schedulers to one replica.
     *
     * @return RedirectResponse A saved acknowledgement; the next deployment applies the definition.
     */
    public function storeProcess(StoreEnvironmentProcessRequest $request, Environment $environment, SaveEnvironmentProcessAction $saveProcess): RedirectResponse
    {
        $this->authorize('update', $environment);
        $saveProcess->handle($environment, $request->validated());

        return back()->with('success', __('Process definition saved. It will be applied on the next deployment.'));
    }

    /**
     * Require the process to belong to the editable environment, remove its definition, and redirect with deployment guidance.
     */
    public function destroyProcess(Environment $environment, EnvironmentProcess $process, DeleteEnvironmentChildAction $deleteChild): RedirectResponse
    {
        $this->authorize('update', $environment);
        $deleteChild->handle($process);

        return back()->with('success', __('Process removed. The next deployment will stop its service.'));
    }

    /**
     * Validate an entitled environment resource and resolve managed service variables or external credentials.
     *
     * @return RedirectResponse The attachment result, or an unsupported managed-resource validation error.
     */
    public function storeResource(StoreEnvironmentResourceRequest $request, Environment $environment, SaveEnvironmentResourceAction $saveResource): RedirectResponse
    {
        $this->authorize('update', $environment);
        try {
            $saveResource->handle($environment, $request->validated());
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        }

        return back()->with('success', __('Resource attached. Its variables will be snapshotted into future deployments.'));
    }

    /**
     * Require the resource to belong to the editable environment, detach its record, and redirect back.
     */
    public function destroyResource(Environment $environment, EnvironmentResource $resource, DeleteEnvironmentChildAction $deleteChild): RedirectResponse
    {
        $this->authorize('update', $environment);
        $deleteChild->handle($resource);

        return back()->with('success', __('Resource detached.'));
    }

    /**
     * Validate locks, deployment windows, strategy, and rollback settings for an editable environment, then save them.
     */
    public function updateDeploymentControls(DeploymentControlsRequest $request, Environment $environment, UpdateDeploymentControlsAction $updateControls): RedirectResponse
    {
        $this->authorize('update', $environment);
        $updateControls->handle($environment, $request->user(), $request->validated());

        return back()->with('success', __('Deployment controls updated.'));
    }

    /**
     * Authorize a non-production environment's deletion and redirect back after removing its record.
     */
    public function destroy(Environment $environment, DeleteEnvironmentAction $deleteEnvironment): RedirectResponse
    {
        $this->authorize('delete', $environment);
        try {
            $deleteEnvironment->handle($environment);
        } catch (EnvironmentDeletionException $exception) {
            abort(422, $exception->getMessage());
        }

        return back()->with('success', __('Environment deleted.'));
    }
}
