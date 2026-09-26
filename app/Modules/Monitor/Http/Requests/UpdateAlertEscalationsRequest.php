<?php

namespace App\Modules\Monitor\Http\Requests;

use App\Modules\Monitor\Models\AlertRule;
use App\Modules\Monitor\Services\CurrentWorkspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateAlertEscalationsRequest extends FormRequest
{
    public function authorize(CurrentWorkspace $currentWorkspace): bool
    {
        $rule = $this->route('alertRule');
        abort_unless($rule instanceof AlertRule && AlertRule::forWorkspace($currentWorkspace->get())->visibleTo($this->user(), $currentWorkspace->get())->whereKey($rule->id)->exists(), 404);
        Gate::authorize('update', $rule);

        return true;
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->isJson() ? $this->json()->all() : $this->request->all();
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $rule = $this->route('alertRule');
        $workspaceId = $rule instanceof AlertRule ? $rule->environment->application->workspace_id : null;

        return [
            'version' => ['required', 'integer', 'min:0'],
            'escalations' => ['sometimes', 'array', 'max:10'],
            'escalations.*' => ['required', 'array'],
            'escalations.*.destination_id' => [
                'nullable', 'integer', Rule::exists('monitor.alert_destinations', 'id')
                    ->where('workspace_id', $workspaceId)->whereNull('deleted_at'),
            ],
            'escalations.*.delay_minutes' => ['nullable', 'integer', 'between:1,10080'],
        ];
    }
}
