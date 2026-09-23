<?php

namespace App\Modules\Monitor\Http\Requests;

use App\Modules\Monitor\Services\CurrentWorkspace;
use App\Modules\Monitor\Services\IntegrationSetupGuide;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class IntegrationSetupRequest extends FormRequest
{
    public function authorize(CurrentWorkspace $currentWorkspace): bool
    {
        return Gate::allows('update', $currentWorkspace->get());
    }

    public function rules(): array
    {
        return [
            'stack' => ['nullable', Rule::in(array_keys(IntegrationSetupGuide::STACKS))],
        ];
    }

    public function stack(): string
    {
        return $this->validated('stack') ?? IntegrationSetupGuide::DEFAULT_STACK;
    }
}
