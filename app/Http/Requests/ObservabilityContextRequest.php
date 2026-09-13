<?php

namespace App\Http\Requests;

use App\Data\ObservabilityContextFilters;
use App\Models\Environment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ObservabilityContextRequest extends FormRequest
{
    /** Authorize the environment policy before validating the investigation window. */
    public function authorize(): bool
    {
        $environment = $this->route('environment');

        return $environment instanceof Environment
            && ($this->user()?->can('view', $environment) ?? false);
    }

    /** @return array<string, list<mixed>> Validate only the finite read filters. */
    public function rules(): array
    {
        return [
            'window' => ['required', Rule::in(array_keys(ObservabilityContextFilters::WINDOWS))],
        ];
    }

    /**
     * Return the validated context window as an immutable query boundary.
     *
     * @return ObservabilityContextFilters Normalized environment evidence filters.
     */
    public function filters(): ObservabilityContextFilters
    {
        /** @var '24h'|'7d'|'30d' $window */
        $window = $this->validated('window');

        return ObservabilityContextFilters::fromWindow($window);
    }

    /** Default the first context visit to one day while rejecting explicit unsupported values. */
    protected function prepareForValidation(): void
    {
        if (! $this->has('window')) {
            $this->merge(['window' => '24h']);
        }
    }
}
