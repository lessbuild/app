<?php

namespace App\Modules\Monitor\Http\Requests;

use App\Modules\Monitor\Models\Issue;
use App\Modules\Monitor\Services\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateIssueRequest extends FormRequest
{
    public const ACTIONS = ['resolve', 'reopen', 'snooze', 'ignore', 'assign'];

    public const SNOOZE_MINUTES = [15 => '15 minutes', 60 => '1 hour', 1440 => '1 day', 10080 => '7 days'];

    public function authorize(CurrentWorkspace $currentWorkspace): bool
    {
        $issue = $this->route('issue');
        abort_unless($issue instanceof Issue && Issue::forWorkspace($currentWorkspace->get())->visibleTo($this->user(), $currentWorkspace->get())->whereKey($issue->id)->exists(), 404);
        Gate::authorize('update', $issue);

        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(self::ACTIONS)],
            'version' => ['required', 'integer', 'min:0'],
            'snooze_minutes' => ['exclude_unless:action,snooze', 'required', 'integer', Rule::in(array_keys(self::SNOOZE_MINUTES))],
            'assignee_id' => [
                'exclude_unless:action,assign', 'present', 'nullable', 'integer',
                Rule::exists('monitor.user_workspace', 'user_id')->where('workspace_id', $this->route('issue')->application->workspace_id)
                    ->whereIn('role', ['owner', 'admin', 'member']),
            ],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
