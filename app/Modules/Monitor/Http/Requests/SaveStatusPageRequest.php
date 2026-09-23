<?php

namespace App\Modules\Monitor\Http\Requests;

use App\Modules\Monitor\Models\StatusPage;
use App\Modules\Monitor\Services\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SaveStatusPageRequest extends FormRequest
{
    public function authorize(CurrentWorkspace $currentWorkspace): bool
    {
        $workspace = $currentWorkspace->get();
        $statusPage = $this->route('statusPage');

        abort_unless(! $statusPage instanceof StatusPage || $statusPage->workspace_id === $workspace->id, 404);
        Gate::authorize('update', $workspace);

        return true;
    }

    public function rules(): array
    {
        $statusPage = $this->route('statusPage');

        return [
            'name' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'slug' => [
                'nullable', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('monitor.status_pages', 'slug')->ignore($statusPage instanceof StatusPage ? $statusPage : null),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'published' => ['sometimes', 'boolean'],
            'monitor_ids' => ['sometimes', 'array', 'max:25'],
            'monitor_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('slug'))) {
            $slug = Str::slug($this->input('slug'));
            $this->merge(['slug' => $slug === '' ? null : $slug]);
        }
    }
}
