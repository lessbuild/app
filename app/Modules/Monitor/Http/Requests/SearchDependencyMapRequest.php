<?php

namespace App\Modules\Monitor\Http\Requests;

use App\Modules\Monitor\Services\Telemetry\ServiceDependencyMap;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchDependencyMapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->query->all();
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'range' => ['nullable', 'string', Rule::in(array_keys(ServiceDependencyMap::RANGES))],
            'environment' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array{range: string, environment?: int} */
    public function filters(): array
    {
        return array_replace(['range' => '24h'], array_filter(
            $this->validated(),
            fn (mixed $value): bool => $value !== null && $value !== '',
        ));
    }
}
