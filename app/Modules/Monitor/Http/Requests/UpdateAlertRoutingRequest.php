<?php

namespace App\Modules\Monitor\Http\Requests;

use App\Modules\Monitor\Models\AlertRule;
use App\Modules\Monitor\Services\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateAlertRoutingRequest extends FormRequest
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

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:0'],
            'destinations' => ['sometimes', 'array', 'max:5'],
            'destinations.*' => ['required', 'integer', 'distinct', 'min:1'],
            'opened' => ['required', 'boolean'], 'recovered' => ['required', 'boolean'],
        ];
    }
}
