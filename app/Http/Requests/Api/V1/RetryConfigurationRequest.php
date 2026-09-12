<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ConfigurationApplication;
use App\Models\ConfigurationOperation;
use App\Models\Project;

class RetryConfigurationRequest extends ConfigurationOperationRequest
{
    /**
     * Preserve API capability middleware, manager policy and receipt-operation relationship checks before body validation.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');
        $application = $this->route('application');
        $operation = $this->route('operation');
        if (! $project instanceof Project || ! $application instanceof ConfigurationApplication
            || ! $operation instanceof ConfigurationOperation
            || ! ($this->user()?->can('manageConfiguration', $project) ?? false)) {
            return false;
        }

        abort_unless((int) $application->review->project_id === (int) $project->id, 404);
        abort_unless($application->relatedOperations()->whereKey($operation->id)->exists(), 404);

        return true;
    }

    /** @return array{0: string, 1: string} */
    protected function unexpectedInput(): array
    {
        return ['operation', 'Retry accepts only the failed operation identity, with no replacement inputs.'];
    }
}
