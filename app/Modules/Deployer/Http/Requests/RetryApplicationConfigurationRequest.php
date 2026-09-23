<?php

namespace App\Modules\Deployer\Http\Requests;

use App\Modules\Deployer\Models\ConfigurationApplication;
use App\Modules\Deployer\Models\ConfigurationOperation;
use App\Modules\Deployer\Models\ConfigurationReview;
use App\Modules\Deployer\Models\Project;

class RetryApplicationConfigurationRequest extends ConfigurationOperationRequest
{
    /**
     * Preserve the deliberate review-author 404 concealment before receipt lookup and replacement-input validation.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');
        $review = $this->route('review');
        $operation = $this->route('operation');
        if (! $project instanceof Project || ! $review instanceof ConfigurationReview
            || ! $operation instanceof ConfigurationOperation || ! $this->managerCanConfigure($project)) {
            return false;
        }

        abort_unless((int) $review->project_id === (int) $project->id, 404);
        abort_unless((int) $review->requested_by === (int) $this->user()?->id, 404);
        $application = ConfigurationApplication::query()->where('configuration_review_id', $review->id)->firstOrFail();
        abort_unless($application->relatedOperations()->whereKey($operation->id)->exists(), 404);

        return true;
    }

    /** @return array{0: string, 1: string} */
    protected function unexpectedInput(): array
    {
        return ['operation', 'Retry accepts only the failed operation identity.'];
    }
}
