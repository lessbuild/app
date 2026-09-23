<?php

namespace App\Modules\Monitor\Http\Requests;

use App\Modules\Monitor\Models\Incident;
use App\Modules\Monitor\Services\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateIncidentRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->isJson() ? $this->json()->all() : $this->request->all();
    }

    public function authorize(CurrentWorkspace $currentWorkspace): bool
    {
        $incident = $this->route('incident');
        abort_unless($incident instanceof Incident && Incident::forWorkspace($currentWorkspace->get())->whereKey($incident->id)->exists(), 404);
        Gate::authorize('update', $incident);

        return true;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(['acknowledge', 'note', 'assign'])],
            'version' => ['required', 'integer', 'min:0'],
            'assignee_id' => [
                'exclude_unless:action,assign', 'present', 'nullable', 'integer',
                Rule::exists('monitor.user_workspace', 'user_id')->where('workspace_id', $this->route('incident')->source()->environment->application->workspace_id)
                    ->whereIn('role', ['owner', 'admin', 'member']),
            ],
            'note' => ['required_if:action,note', 'nullable', 'string', 'max:1000'],
        ];
    }
}
