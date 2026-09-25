<?php

namespace App\Core\Http\Requests\Deployer;

use App\Modules\Deployer\Rules\Hostname;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class UpdateProjectPreviewSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('platform') !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'preview_enabled' => ['required', 'boolean'],
            'preview_domain' => ['required_if:preview_enabled,true', 'nullable', 'string', 'max:200', new Hostname],
            'preview_ttl_hours' => ['required', 'integer', 'between:1,720'],
        ];
    }

    /** Keep normalization aligned with Deployer's native preview settings request. */
    protected function prepareForValidation(): void
    {
        $domain = preg_replace('#^https?://#i', '', trim((string) $this->input('preview_domain')));

        $this->merge([
            'preview_enabled' => $this->boolean('preview_enabled'),
            'preview_domain' => rtrim((string) $domain, '/'),
        ]);
    }

    /** Do not flash forged extra fields, which may contain values this form never renders. */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(redirect()->back()->withErrors($validator, $this->errorBag));
    }
}
