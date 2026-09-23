<?php

namespace App\Modules\Monitor\Http\Requests;

use App\Modules\Monitor\Data\Telemetry\IssueStatus;
use App\Modules\Monitor\Models\Issue;
use App\Modules\Monitor\Services\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SearchIssuesRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(CurrentWorkspace $currentWorkspace): bool
    {
        if ($issue = $this->route('issue')) {
            abort_unless($issue instanceof Issue && Issue::forWorkspace($currentWorkspace->get())->whereKey($issue->id)->exists(), 404);
            Gate::authorize('view', $issue);
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->query->all();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', Rule::in(['all', ...array_column(IssueStatus::cases(), 'value')])],
            'q' => ['nullable', 'string', 'max:255'],
            'application' => ['nullable', 'integer', 'min:1'],
            'severity' => ['nullable', 'string', Rule::in(['debug', 'info', 'warning', 'error', 'critical'])],
            'ownership' => ['nullable', 'string', Rule::in(['any', 'mine', 'unassigned'])],
            'page' => ['nullable', 'integer', 'between:1,100000'],
            'events_page' => ['nullable', 'integer', 'between:1,100000'],
            'activity_page' => ['nullable', 'integer', 'between:1,100000'],
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return array_replace(['status' => 'open', 'ownership' => 'any'], array_filter($this->validated(), fn (mixed $value): bool => $value !== null && $value !== ''));
    }
}
