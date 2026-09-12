<?php

namespace App\Http\Requests;

use App\Models\AccessRequest;
use App\Services\RegistrationAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreAccessRequestRequest extends FormRequest
{
    /**
     * Preserve the public access-request route while allowing the controller to handle closed-registration input.
     */
    public function authorize(): bool
    {
        return ! app(RegistrationAccess::class)->allowsNewUser();
    }

    /**
     * Validate the applicant fields, skipping validation for the intentional honeypot no-op.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        if (filled($this->input('website'))) {
            return [];
        }

        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'company' => ['nullable', 'string', 'max:160'],
            'team_size' => ['nullable', Rule::in(AccessRequest::TEAM_SIZES)],
            'plan' => ['nullable', Rule::in(array_keys(config('billing.plans', [])))],
            'use_case' => ['required', 'string', 'min:20', 'max:2000'],
        ];
    }

    /**
     * Normalize the applicant email before validation and preserve the existing trimmed stored fields.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => Str::lower(trim((string) $this->input('email'))),
        ]);
    }

    /**
     * Return only normalized, validated fields for the intake action.
     *
     * @return array{name: string, email: string, company: ?string, team_size: ?string, plan: ?string, use_case: string}
     */
    public function applicantAttributes(): array
    {
        $data = $this->validated();

        return [
            'name' => trim($data['name']),
            'email' => $data['email'],
            'company' => filled($data['company'] ?? null) ? trim($data['company']) : null,
            'team_size' => $data['team_size'] ?? null,
            'plan' => $data['plan'] ?? null,
            'use_case' => trim($data['use_case']),
        ];
    }

    /**
     * Preserve the pre-validation redirect when public registration is open.
     *
     * @return never
     */
    protected function failedAuthorization(): never
    {
        if (app(RegistrationAccess::class)->allowsNewUser()) {
            throw new HttpResponseException(redirect()->route('register'));
        }

        throw new AuthorizationException;
    }
}
