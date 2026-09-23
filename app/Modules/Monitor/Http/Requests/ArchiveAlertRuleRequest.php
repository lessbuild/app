<?php

namespace App\Modules\Monitor\Http\Requests;

use App\Modules\Monitor\Models\AlertRule;
use App\Modules\Monitor\Services\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ArchiveAlertRuleRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->isJson() ? $this->json()->all() : $this->request->all();
    }

    public function authorize(CurrentWorkspace $currentWorkspace): bool
    {
        $rule = $this->route('alertRule');
        abort_unless($rule instanceof AlertRule && AlertRule::forWorkspace($currentWorkspace->get())->whereKey($rule->id)->exists(), 404);
        Gate::authorize('delete', $rule);

        return true;
    }

    /** @return array<string, array<string>> */
    public function rules(): array
    {
        return ['version' => ['required', 'integer', 'min:0']];
    }
}
