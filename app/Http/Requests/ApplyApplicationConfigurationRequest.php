<?php

namespace App\Http\Requests;

use App\Models\ConfigurationReview;
use App\Models\Project;

class ApplyApplicationConfigurationRequest extends ConfigurationOperationRequest
{
    /**
     * Preserve the project's manager check before the review-parent 404 and operation-input validation.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');
        $review = $this->route('review');
        if (! $project instanceof Project || ! $review instanceof ConfigurationReview || ! $this->managerCanConfigure($project)) {
            return false;
        }

        abort_unless((int) $review->project_id === (int) $project->id, 404);

        return true;
    }

    /** @return array{0: string, 1: string} */
    protected function unexpectedInput(): array
    {
        return ['review', 'Apply accepts only the saved review.'];
    }
}
