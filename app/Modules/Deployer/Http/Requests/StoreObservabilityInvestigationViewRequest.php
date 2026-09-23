<?php

namespace App\Modules\Deployer\Http\Requests;

use App\Modules\Deployer\Models\ObservabilityInvestigationView;
use Illuminate\Validation\Rule;

class StoreObservabilityInvestigationViewRequest extends ObservabilityContextRequest
{
    /** @return array<string, list<mixed>> Validate a bounded name and retention choice with the context filters. */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'name' => ['required', 'string', 'max:60'],
            'expires_in_days' => ['required', 'integer', Rule::in(ObservabilityInvestigationView::EXPIRY_DAYS)],
        ];
    }

    /** Return the trimmed validated named-view label. */
    public function viewName(): string
    {
        return (string) $this->validated('name');
    }

    /** Return the validated finite retention choice. */
    public function expirationDays(): int
    {
        return (int) $this->validated('expires_in_days');
    }

    /** Normalize the label while preserving explicit null rejection in the parent filter contract. */
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $name = $this->input('name');

        $this->merge([
            'name' => is_string($name) || is_numeric($name)
                ? str((string) $name)->trim()->limit(60, '')->toString()
                : '',
            'expires_in_days' => $this->input('expires_in_days', ObservabilityInvestigationView::DEFAULT_EXPIRY_DAYS),
        ]);
    }
}
