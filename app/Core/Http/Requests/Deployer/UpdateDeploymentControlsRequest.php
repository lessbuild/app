<?php

namespace App\Core\Http\Requests\Deployer;

use App\Core\Contracts\Deployer\WorkspaceDeployerDeploymentControlsProvider;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectEnvironment;
use App\Core\Models\Workspace;
use App\Core\Services\WorkspaceDeployerAdministrationProviderRegistry;
use App\Modules\Deployer\Models\Environment;
use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateDeploymentControlsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The typed provider rechecks Core membership, exact mappings, current native
        // organization, and Deployer's native update policy before invoking the action.
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'deployment_locked' => ['required', 'boolean'],
            'deployment_lock_reason' => ['nullable', 'string', 'max:500'],
            'deployment_window_enabled' => ['required', 'boolean'],
            'deployment_window_days' => ['nullable', 'array', 'min:1'],
            'deployment_window_days.*' => ['integer', 'between:1,7', 'distinct'],
            'deployment_window_start' => ['nullable', 'date_format:H:i'],
            'deployment_window_end' => ['nullable', 'date_format:H:i'],
            'deployment_window_timezone' => ['nullable', 'string', Rule::in(DateTimeZone::listIdentifiers())],
            'deployment_strategy' => ['required', Rule::in(Environment::DEPLOYMENT_STRATEGIES)],
            'rolling_pause_seconds' => ['required', 'integer', Rule::in([0, 1, 2, 5, 10, 30])],
            'automatic_rollback' => ['required', 'boolean'],
        ];
    }

    /** Keep the native request's defaults for omitted rollout values. */
    protected function prepareForValidation(): void
    {
        $workspace = $this->route('workspace');
        $project = $this->route('project');
        $environment = $this->route('environment');
        $actor = $this->user('platform');

        if (! $workspace instanceof Workspace || ! $project instanceof Project || ! $environment instanceof ProjectEnvironment
            || ! $actor instanceof PlatformUser) {
            return;
        }

        $provider = app(WorkspaceDeployerAdministrationProviderRegistry::class)->deploymentControls();
        if (! $provider instanceof WorkspaceDeployerDeploymentControlsProvider) {
            return;
        }

        $snapshot = $provider->snapshot($actor, $workspace, $project, $environment);
        $this->mergeIfMissing([
            'deployment_strategy' => $snapshot->deploymentStrategy,
            'rolling_pause_seconds' => $snapshot->rollingPauseSeconds,
            'automatic_rollback' => false,
        ]);
    }

    /** Match the native deployment-window cross-field validation and message. */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $data = $this->all();
            if ($data['deployment_window_enabled'] && (empty($data['deployment_window_days'])
                || empty($data['deployment_window_start']) || empty($data['deployment_window_end'])
                || empty($data['deployment_window_timezone']))) {
                $validator->errors()->add(
                    'deployment_window_days',
                    __('Choose days, start and end times, and a timezone for the window.'),
                );
            }
        }];
    }
}
