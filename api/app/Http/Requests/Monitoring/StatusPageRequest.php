<?php

declare(strict_types=1);

namespace App\Http\Requests\Monitoring;

use App\Models\StatusPage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Authorisation happens in SaveStatusPage (account settings access). */
final class StatusPageRequest extends FormRequest
{
    /**
     * Get the validation rules: a page's name, public slug (unique, and not the reserved `subscriptions`),
     * description, whether it's published, and up to 25 monitors to show.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $page = $this->route('page');

        return [
            'name' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'slug' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::notIn(['subscriptions']),
                Rule::unique('status_pages', 'slug')->ignore($page instanceof StatusPage ? $page->id : (is_scalar($page) ? (int) $page : null))],
            'description' => ['nullable', 'string', 'max:1000'],
            'published' => ['sometimes', 'boolean'],
            'monitor_ids' => ['sometimes', 'array', 'max:25'],
            'monitor_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
            'monthly_report' => ['sometimes', 'boolean'],
            'component_groups' => ['sometimes', 'array', 'max:25'],
            'component_groups.*' => ['nullable', 'string', 'max:80'],
        ];
    }

    /**
     * Get the messages for the slug's format, uniqueness and reserved names.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['slug.regex' => __('Use lowercase letters, numbers and single dashes.'), 'slug.unique' => __('That public address is already taken.'), 'slug.not_in' => __('That public address is reserved.')];
    }

    /**
     * Slugify what was typed as the slug, treating an empty result as none.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('slug'))) {
            $slug = Str::slug($this->input('slug'));
            $this->merge(['slug' => $slug === '' ? null : $slug]);
        }
    }
}
