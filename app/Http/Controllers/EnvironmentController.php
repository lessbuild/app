<?php

namespace App\Http\Controllers;

use App\Actions\Environment\SaveEnvironmentProcessAction;
use App\Actions\Environment\SaveEnvironmentResourceAction;
use App\Actions\Environment\SaveEnvironmentVariableAction;
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
use App\Services\Entitlements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EnvironmentController extends Controller
{
    /**
     * Use workspace entitlements to gate worker, resource, scaling, and hibernation configuration.
     */
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Validate environment placement and runtime settings for an editable application and assign an available slug.
     *
     * @return RedirectResponse The creation result, or a validation error for a second production environment.
     */
    public function store(EnvironmentRequest $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);
        $data = $request->validated();
        $this->enforceRuntimeFeatures($project, $data);
        if (($data['is_protected'] || $data['requires_deployment_approval']) && ! $project->organization->permits($request->user(), 'manage')) {
            abort(403);
        }
        if ($data['type'] === 'production' && $project->environments()->where('type', 'production')->exists()) {
            return back()->withErrors(['type' => __('This application already has a production environment.')])->withInput();
        }
        $base = Str::slug($data['name']) ?: 'environment';
        $slug = $base;
        $suffix = 2;
        while ($project->environments()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }
        $project->environments()->create([...$data, 'slug' => $slug]);

        return back()->with('success', __('Environment created.'));
    }

    /**
     * Validate environment settings while retaining omitted runtime values and enforcing changed-feature entitlements.
     *
     * @return RedirectResponse The saved result, or a validation error for a second production environment.
     */
    public function update(EnvironmentRequest $request, Environment $environment): RedirectResponse
    {
        $this->authorize('update', $environment);
        $data = $request->validated();
        $this->enforceRuntimeFeatures($environment->project, $data, $environment);
        if ($data['type'] === 'production' && $environment->project->environments()->where('type', 'production')->whereKeyNot($environment->id)->exists()) {
            return back()->withErrors(['type' => __('This application already has a production environment.')])->withInput();
        }
        $environment->update($data);

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
    public function destroyVariable(Environment $environment, EnvironmentVariable $variable): RedirectResponse
    {
        $this->authorize('update', $environment);
        abort_unless((int) $variable->environment_id === (int) $environment->id, 404);
        $variable->delete();

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
    public function destroyProcess(Environment $environment, EnvironmentProcess $process): RedirectResponse
    {
        $this->authorize('update', $environment);
        abort_unless($process->environment_id === $environment->id, 404);
        $process->delete();

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
    public function destroyResource(Environment $environment, EnvironmentResource $resource): RedirectResponse
    {
        $this->authorize('update', $environment);
        abort_unless($resource->environment_id === $environment->id, 404);
        $resource->delete();

        return back()->with('success', __('Resource detached.'));
    }

    /**
     * Validate locks, deployment windows, strategy, and rollback settings for an editable environment, then save them.
     */
    public function updateDeploymentControls(DeploymentControlsRequest $request, Environment $environment): RedirectResponse
    {
        $this->authorize('update', $environment);
        $data = $request->validated();

        $environment->update([
            'deployment_locked_at' => $data['deployment_locked'] ? ($environment->deployment_locked_at ?? now()) : null,
            'deployment_locked_by' => $data['deployment_locked'] ? $request->user()->id : null,
            'deployment_lock_reason' => $data['deployment_locked'] ? ($data['deployment_lock_reason'] ?: null) : null,
            'deployment_window_days' => $data['deployment_window_enabled'] ? array_values($data['deployment_window_days']) : null,
            'deployment_window_start' => $data['deployment_window_enabled'] ? $data['deployment_window_start'] : null,
            'deployment_window_end' => $data['deployment_window_enabled'] ? $data['deployment_window_end'] : null,
            'deployment_window_timezone' => $data['deployment_window_enabled'] ? $data['deployment_window_timezone'] : null,
            'deployment_strategy' => $data['deployment_strategy'],
            'rolling_pause_seconds' => $data['rolling_pause_seconds'],
            'automatic_rollback' => $data['automatic_rollback'],
        ]);

        return back()->with('success', __('Deployment controls updated.'));
    }

    /**
     * Authorize a non-production environment's deletion and redirect back after removing its record.
     */
    public function destroy(Environment $environment): RedirectResponse
    {
        $this->authorize('delete', $environment);
        abort_if($environment->type === 'production', 422, 'The production environment cannot be deleted.');
        $environment->delete();

        return back()->with('success', __('Environment deleted.'));
    }

    /**
     * Enforce paid scaling or hibernation only when submitted values enable or change those features.
     *
     * @param  array<string, mixed>  $data  Validated environment attributes.
     * @param  Environment|null  $current  Existing values for updates, or null when creating an environment.
     */
    private function enforceRuntimeFeatures(Project $project, array $data, ?Environment $current = null): void
    {
        $scalingChanged = ! $current
            || (int) $data['minimum_replicas'] !== $current->minimum_replicas
            || (int) $data['maximum_replicas'] !== $current->maximum_replicas;
        if ($scalingChanged && ((int) ($data['minimum_replicas'] ?? 1) !== 1 || (int) ($data['maximum_replicas'] ?? 1) !== 1)) {
            $this->entitlements->enforce($project->organization, 'scaling');
        }
        $requestedHibernation = is_null($data['hibernate_after_minutes'] ?? null) ? null : (int) $data['hibernate_after_minutes'];
        $hibernationChanged = ! $current || $requestedHibernation !== $current->hibernate_after_minutes;
        if ($hibernationChanged && ! is_null($data['hibernate_after_minutes'] ?? null)) {
            $this->entitlements->enforce($project->organization, 'hibernation');
        }
    }
}
