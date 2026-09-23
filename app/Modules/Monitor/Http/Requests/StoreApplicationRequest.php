<?php

namespace App\Modules\Monitor\Http\Requests;

use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Services\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreApplicationRequest extends FormRequest
{
    public function authorize(CurrentWorkspace $currentWorkspace): bool
    {
        Gate::authorize('update', $this->route('application') ?? $currentWorkspace->get());

        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'framework' => ['required', 'string', 'max:80'],
            'framework_version' => ['nullable', 'string', 'max:40'],
            'accent' => ['sometimes', Rule::in(Application::ACCENTS)],
        ];
    }
}
