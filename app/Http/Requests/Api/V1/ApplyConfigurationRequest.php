<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ConfigurationReview;
use App\Models\Project;

class ApplyConfigurationRequest extends ConfigurationOperationRequest
{
    /**
     * Preserve API capability middleware, manager policy and review-parent lookup order before body validation.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');
        $review = $this->route('review');
        if (! $project instanceof Project || ! $review instanceof ConfigurationReview
            || ! ($this->user()?->can('manageConfiguration', $project) ?? false)) {
            return false;
        }

        abort_unless((int) $review->project_id === (int) $project->id, 404);

        return true;
    }

    /** @return array{0: string, 1: string} */
    protected function unexpectedInput(): array
    {
        return ['review', 'Apply accepts only the saved review identity, with no replacement inputs.'];
    }
}
